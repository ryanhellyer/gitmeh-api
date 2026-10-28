<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Invokes the configured inference platform for a single chat turn, retrying
 * transient failures and walking a list of fallback models.
 *
 * Moved out of the commit-message generator so both the commit endpoint and the
 * translations endpoint get the same retry/backoff/fallback semantics.
 */
final class ModelRunner implements ModelRunnerInterface
{
    public function __construct(
        private readonly PlatformInterface $platform,
    ) {
    }

    public function run(
        string $model,
        MessageBag $messages,
        array $options = [],
        array $fallbackModels = [],
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
    ): array {
        $maxRetries = max(1, $maxRetries);
        $models = $this->buildModelList($model, $fallbackModels);
        $lastError = null;

        foreach ($models as $i => $m) {
            $result = $this->tryModelWithRetry($m, $messages, $options, $maxRetries);

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

    /**
     * @param array<string, mixed> $options
     *
     * @return array{ok: true, text: string, model: string}|array{ok: false, error: string}
     */
    private function tryModelWithRetry(string $model, MessageBag $messages, array $options, int $maxRetries): array
    {
        $lastErr = '';

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            if ($attempt > 0) {
                sleep(1 << ($attempt - 1));
            }

            $result = $this->doChatRequest($model, $messages, $options);

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

            error_log("\n  {$model} attempt " . ($attempt + 1) . '/' . $maxRetries . ": {$lastErr}\n");
        }

        error_log("\n  {$model} failed after " . $maxRetries . " attempts\n");

        return ['ok' => false, 'error' => $lastErr !== '' ? $lastErr : 'unknown error'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{ok: true, text: string, model: string}|array{ok: false, error: string}
     */
    private function doChatRequest(string $model, MessageBag $messages, array $options): array
    {
        try {
            $result = $this->platform->invoke($model, $messages, $options);

            $text = $result->asText();

            if (trim($text) === '') {
                return ['ok' => false, 'error' => 'empty assistant content'];
            }

            return ['ok' => true, 'text' => $text, 'model' => $model];
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
