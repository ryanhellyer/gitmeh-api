<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GitmehCommitMessageGenerator
{
    private const DEFAULT_PROMPT = 'Write a short, professional git commit message for these changes. Use imperative mood. Only return the message text:';

    private const MAX_RETRIES_PER_MODEL = 3;

    private const TEMPERATURE = 0.3;

    private const MAX_TOKENS = 4096;

    /**
     * @param  ?int  $inferenceTimeoutSeconds  When null, uses `services.openrouter.timeout` from config.
     * @param  ?string  $provider  Upstream provider name from config/ai.php (default: openrouter).
     * @param  ?string  $apiKey  Client-supplied API key; when null uses the provider's configured key.
     * @param  ?string  $model  Model identifier; when null uses the provider's default model.
     * @param  string[]  $fallbackModels  Models to try if primary fails with a retryable/context-length error.
     * @return array{ok: true, message: string}|array{ok: false, error: string, status: int}
     */
    public function generate(
        string $instruction,
        string $unifiedDiff,
        ?int $inferenceTimeoutSeconds = null,
        ?string $provider = null,
        ?string $apiKey = null,
        ?string $model = null,
        array $fallbackModels = [],
    ): array {
        $provider ??= 'openrouter';
        /** @var array<string, mixed>|null $providerConfig */
        $providerConfig = config("ai.providers.{$provider}");

        if (! is_array($providerConfig)) {
            return ['ok' => false, 'error' => "Unknown provider '{$provider}'.", 'status' => 400];
        }

        $baseUrl = $this->resolveBaseUrl($provider, $providerConfig);
        $apiKey ??= $this->resolveApiKey($providerConfig);
        $model ??= $this->resolveModel($providerConfig, $provider);
        $timeout = $inferenceTimeoutSeconds ?? (int) (config('services.openrouter.timeout') ?? 120);

        if ($apiKey === null || $apiKey === '') {
            return ['ok' => false, 'error' => "Error: API key is missing for provider '{$provider}'.", 'status' => 500];
        }
        if ($model === '') {
            return ['ok' => false, 'error' => "Error: model is empty for provider '{$provider}'.", 'status' => 500];
        }

        $models = $this->buildModelList($model, $fallbackModels);
        $lastError = null;

        foreach ($models as $i => $m) {
            /** @var array{ok: true, message: string}|array{ok: false, error: string} $result */
            $result = $this->tryModelWithRetry($baseUrl, $apiKey, $m, $instruction, $unifiedDiff, $timeout);

            if ($result['ok']) {
                return $result;
            }

            $lastError = $result;

            if ($i < count($models) - 1) {
                fwrite(STDERR, "\n  → trying fallback model \"{$models[$i + 1]}\" ...\n");
            }
        }

        $modelsList = implode(', ', $models);
        $errorMsg = $lastError !== null ? $lastError['error'] : 'unknown error';

        return ['ok' => false, 'error' => "all " . count($models) . " models failed: {$errorMsg} (models: {$modelsList})", 'status' => 502];
    }

    /**
     * @return array{ok: true, message: string}|array{ok: false, error: string}
     */
    private function tryModelWithRetry(string $baseUrl, string $apiKey, string $model, string $instruction, string $diff, int $timeout): array
    {
        $lastErr = '';

        for ($attempt = 0; $attempt < self::MAX_RETRIES_PER_MODEL; $attempt++) {
            if ($attempt > 0) {
                sleep(1 << ($attempt - 1));
            }

            /** @var array{ok: true, message: string}|array{ok: false, error: string} $result */
            $result = $this->doChatRequest($baseUrl, $apiKey, $model, $instruction, $diff, $timeout);

            if ($result['ok']) {
                return $result;
            }

            $lastErr = $result['error'];

            if ($this->isContextLengthError($lastErr)) {
                fwrite(STDERR, "\n  {$model}: context length exceeded\n");

                return ['ok' => false, 'error' => $lastErr];
            }

            if (! $this->isRetryable($lastErr)) {
                fwrite(STDERR, "\n  {$model}: {$lastErr}\n");

                return ['ok' => false, 'error' => $lastErr];
            }

            fwrite(STDERR, "\n  {$model} attempt " . ($attempt + 1) . '/' . self::MAX_RETRIES_PER_MODEL . ": {$lastErr}\n");
        }

        fwrite(STDERR, "\n  {$model} failed after " . self::MAX_RETRIES_PER_MODEL . " attempts\n");

        return ['ok' => false, 'error' => $lastErr !== '' ? $lastErr : 'unknown error'];
    }

    /**
     * @return array{ok: true, message: string}|array{ok: false, error: string}
     */
    private function doChatRequest(string $baseUrl, string $apiKey, string $model, string $instruction, string $diff, int $timeout): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instruction],
                ['role' => 'user', 'content' => "Unified diff:\n" . $diff],
            ],
            'temperature' => self::TEMPERATURE,
            'max_tokens' => self::MAX_TOKENS,
        ];

        try {
            $http = Http::timeout($timeout)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ]);

            if (str_contains(strtolower($baseUrl), 'openrouter.ai')) {
                $referer = config('app.url');
                $title = config('app.name', 'gitmeh');
                $http = $http->withHeaders([
                    'HTTP-Referer' => is_string($referer) ? $referer : '',
                    'X-Title' => is_string($title) ? $title : 'gitmeh',
                ]);
            }

            $response = $http->post("{$baseUrl}/chat/completions", $payload);

            $status = $response->status();
            $body = $response->body();

            if ($status < 200 || $status >= 300) {
                $message = $this->summarizeApiError($body);

                return ['ok' => false, 'error' => "{$status} | {$message}"];
            }

            /** @var mixed $parsed */
            $parsed = $response->json();

            if (! is_array($parsed)) {
                return ['ok' => false, 'error' => 'decode response: invalid JSON (body: ' . $this->truncate($body, 800) . ')'];
            }

            $choices = $parsed['choices'] ?? [];

            if (! is_array($choices) || $choices === []) {
                return ['ok' => false, 'error' => 'no choices in response: ' . $this->truncate($body, 800)];
            }

            /** @var mixed $firstChoice */
            $firstChoice = $choices[0] ?? null;

            if (! is_array($firstChoice)) {
                return ['ok' => false, 'error' => 'no choices in response: ' . $this->truncate($body, 800)];
            }

            /** @var mixed $messageData */
            $messageData = $firstChoice['message'] ?? null;

            if (! is_array($messageData)) {
                return ['ok' => false, 'error' => 'empty assistant content: ' . $this->truncate($body, 800)];
            }

            $content = $messageData['content'] ?? null;

            if (! is_string($content) || trim($content) === '') {
                return ['ok' => false, 'error' => 'empty assistant content: ' . $this->truncate($body, 800)];
            }

            $firstLine = explode("\n", trim($content), 2)[0];

            return ['ok' => true, 'message' => trim($firstLine)];
        } catch (ConnectionException $e) {
            return ['ok' => false, 'error' => 'connection error: ' . $e->getMessage()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function defaultInstruction(): string
    {
        $instruction = config('services.openrouter.prompt');
        if (! is_string($instruction) || trim($instruction) === '') {
            return self::DEFAULT_PROMPT;
        }

        return $instruction;
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    private function resolveBaseUrl(string $provider, array $providerConfig): string
    {
        $defaults = [
            'openrouter' => 'https://openrouter.ai/api/v1',
            'openai' => 'https://api.openai.com/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'groq' => 'https://api.groq.com/openai/v1',
            'mistral' => 'https://api.mistral.ai/v1',
            'ollama' => 'http://localhost:11434',
            'xai' => 'https://api.x.ai/v1',
        ];

        $url = $providerConfig['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : ($defaults[$provider] ?? 'https://openrouter.ai/api/v1');
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    private function resolveApiKey(array $providerConfig): ?string
    {
        $key = $providerConfig['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    private function resolveModel(array $providerConfig, string $provider): string
    {
        /** @var mixed $models */
        $models = $providerConfig['models'] ?? [];

        if (is_array($models)) {
            /** @var mixed $text */
            $text = $models['text'] ?? null;

            if (is_array($text)) {
                $default = $text['default'] ?? null;

                if (is_string($default) && $default !== '') {
                    return $default;
                }
            }
        }

        $defaults = [
            'openrouter' => 'google/gemma-3-4b-it',
            'openai' => 'gpt-4o-mini',
            'groq' => 'llama-3.1-8b-instant',
            'mistral' => 'mistral-small-latest',
            'ollama' => 'llama3.2',
            'xai' => 'grok-2-latest',
        ];

        return $defaults[$provider] ?? 'google/gemma-3-4b-it';
    }

    /**
     * @param  string[]  $fallbacks
     * @return string[]
     */
    private function buildModelList(string $primary, array $fallbacks): array
    {
        $models = [$primary];

        foreach ($fallbacks as $m) {
            $m = trim($m);
            if ($m !== '' && $m !== $primary && ! in_array($m, $models, true)) {
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

    private function summarizeApiError(string $body): string
    {
        /** @var mixed $parsed */
        $parsed = json_decode($body, true);

        if (is_array($parsed)) {
            /** @var mixed $error */
            $error = $parsed['error'] ?? null;

            if (is_array($error)) {
                $message = $error['message'] ?? null;

                if (is_string($message) && trim($message) !== '') {
                    return $message;
                }
            }
        }

        return 'raw body: ' . $this->truncate($body, 800);
    }

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max) . '...';
    }
}
