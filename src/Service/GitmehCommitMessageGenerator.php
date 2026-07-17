<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GitmehCommitMessageGenerator
{
    private const MAX_RETRIES_PER_MODEL = 3;

    public function __construct(
        private readonly PlatformInterface $platform,
        #[Autowire('%gitmeh.prompt%')]
        private readonly string $defaultPrompt,
        #[Autowire('%gitmeh.default_model%')]
        private readonly string $defaultModel,
    ) {
    }

    /**
     * @param string[] $fallbackModels
     *
     * @return array{ok: true, message: string, model: string}|array{ok: false, error: string, status: int}
     */
    public function generate(
        string $instruction,
        string $unifiedDiff,
        ?int $inferenceTimeoutSeconds = null,
        ?string $model = null,
        array $fallbackModels = [],
    ): array {
        // The Laravel app treats the literal "gitmeh-hosted" model alias as "use the
        // server default" — forwarding it to OpenRouter verbatim would 404.
        if ($model === 'gitmeh-hosted') {
            $model = null;
        }
        $model ??= $this->defaultModel;
        $models = $this->buildModelList($model, $fallbackModels);
        $lastError = null;

        foreach ($models as $i => $m) {
            $result = $this->tryModelWithRetry($m, $instruction, $unifiedDiff);

            if ($result['ok']) {
                return $result;
            }

            $lastError = $result;

            if ($i < count($models) - 1) {
                error_log("\n  → trying fallback model \"{$models[$i + 1]}\" ...\n");
            }
        }

        $modelsList = implode(', ', $models);
        $errorMsg = $lastError !== null ? $lastError['error'] : 'unknown error';

        return [
            'ok' => false,
            'error' => 'all ' . count($models) . ' models failed: ' . $errorMsg . ' (models: ' . $modelsList . ')',
            'status' => 502,
        ];
    }

    public function defaultInstruction(): string
    {
        return $this->defaultPrompt;
    }

    /**
     * @return array{ok: true, message: string, model: string}|array{ok: false, error: string}
     */
    private function tryModelWithRetry(string $model, string $instruction, string $diff): array
    {
        $lastErr = '';

        for ($attempt = 0; $attempt < self::MAX_RETRIES_PER_MODEL; $attempt++) {
            if ($attempt > 0) {
                sleep(1 << ($attempt - 1));
            }

            $result = $this->doChatRequest($model, $instruction, $diff);

            if ($result['ok']) {
                return $result;
            }

            $lastErr = $result['error'];

            if ($this->isContextLengthError($lastErr)) {
                error_log("\n  {$model}: context length exceeded\n");

                return ['ok' => false, 'error' => $lastErr];
            }

            if (!$this->isRetryable($lastErr)) {
                error_log("\n  {$model}: {$lastErr}\n");

                return ['ok' => false, 'error' => $lastErr];
            }

            error_log("\n  {$model} attempt " . ($attempt + 1) . '/' . self::MAX_RETRIES_PER_MODEL . ": {$lastErr}\n");
        }

        error_log("\n  {$model} failed after " . self::MAX_RETRIES_PER_MODEL . " attempts\n");

        return ['ok' => false, 'error' => $lastErr !== '' ? $lastErr : 'unknown error'];
    }

    /**
     * @return array{ok: true, message: string, model: string}|array{ok: false, error: string}
     */
    private function doChatRequest(string $model, string $instruction, string $diff): array
    {
        try {
            $messages = new MessageBag(
                Message::forSystem($instruction),
                Message::ofUser("Unified diff:\n" . $diff),
            );

            $result = $this->platform->invoke($model, $messages, [
                'temperature' => 0.3,
                'max_output_tokens' => 4096,
            ]);

            $text = $result->asText();

            if (trim($text) === '') {
                return ['ok' => false, 'error' => 'empty assistant content'];
            }

            $firstLine = explode("\n", trim($text), 2)[0];

            return ['ok' => true, 'message' => trim($firstLine), 'model' => $model];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param string[] $fallbacks
     *
     * @return string[]
     */
    private function buildModelList(string $primary, array $fallbacks): array
    {
        $models = [$primary];
        foreach ($fallbacks as $m) {
            $m = trim($m);
            if ($m !== '' && $m !== $primary && !in_array($m, $models, true)) {
                $models[] = $m;
            }
        }

        return $models;
    }

    private function isRetryable(string $error): bool
    {
        $patterns = [
            'timeout',
            'connection refused',
            'no such host',
            'connection reset',
            'TLS handshake',
            '429',
            '500',
            '502',
            '503',
            '504',
            'Provider returned error',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($error, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isContextLengthError(string $error): bool
    {
        return str_contains($error, 'maximum context length')
            || str_contains($error, 'context length')
            || str_contains($error, 'too many tokens');
    }
}
