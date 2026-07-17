# Convert Laravel AI App to Symfony with OpenRouter

## Overview

The Laravel app (`ai.hellyer.test`) provides an OpenAI-compatible chat completions API at `POST /v1/chat/completions` and a legacy `POST /gitmeh` endpoint. Both call OpenRouter to generate Git commit messages from unified diffs. Rate limiting is enforced per IP via Redis, and Bearer token auth is optional (forwarding client keys to OpenRouter).

We are rebuilding this in Symfony (`ai-symfony.hellyer.test`) with **OpenRouter as the only provider**. Other providers from the Laravel config are not needed.

This plan covers the full conversion: LLM platform setup, services, controllers, middleware equivalents, rate limiting, and the scheduled task.

> **Corrections applied after verifying against source.** The AI Platform API, the OpenRouter bridge `Factory.php`, and the AI Bundle docs were checked directly. Fixes from the first draft:
> - `Message::ofSystem()` does not exist → use `Message::forSystem()` (system) with `Message::ofUser()` (user).
> - Platform option is `max_output_tokens`, not `max_tokens` (silently ignored otherwise).
> - `array_all()` is PHP 8.4+; this app targets PHP ≥8.2 → replaced with `array_filter` + count check.
> - The OpenRouter bridge's base URL is `https://openrouter.ai/api` (it appends `/v1/chat/completions`); the bridge has no `http_referer`/`x_title`/`default_model` config keys → headers injected via a scoped HttpClient (Phase 1.7).
> - Rate limiting now applies only to `POST /gitmeh` and `POST /v1/chat/completions` (GET status page must not consume quota), and runs *after* the 405/401/413 checks so rejected requests don't burn a daily slot — matching Laravel's middleware order.
> - The `gitmeh-hosted` model alias is normalised to the default model (Laravel does this; forwarding it verbatim would 404 on OpenRouter).
> - `gitmeh.yaml` env-processor syntax fixed (no colons inside `default:` fallbacks, no `default:120:int:` chaining).
> - Trusted-proxies config added so `getClientIp()` returns the real client IP behind a reverse proxy (the Laravel app sets this).
> - Phase 6 `PlatformFactory` corrected to the real `Symfony\AI\Platform\Bridge\OpenRouter\Factory` class and its actual `createPlatform()` signature.

---

## Phase 1: Dependencies & Configuration

### 1.1 Install Composer packages

```bash
composer require symfony/ai-bundle symfony/ai-open-router-platform predis/predis
```

| Package | Purpose |
|---|---|
| `symfony/ai-bundle` | Symfony DI integration for the AI platform, `PlatformInterface` autowiring, profiler panel, YAML config |
| `symfony/ai-open-router-platform` | Dedicated OpenRouter bridge (no need to hack OpenAI with a custom base URL) |
| `predis/predis` | Redis client for rate limiting (Symfony's Cache component can use Redis too, but a direct client is simpler for hash-based counters) |

### 1.2 Environment variables

Add to `.env`:

```ini
# OpenRouter
OPENROUTER_API_KEY=
OPENROUTER_MODEL=google/gemma-3-4b-it
# NOTE: The OpenRouter bridge's base URL is "https://openrouter.ai/api" — it appends
# "/v1/chat/completions" itself. Do NOT include "/v1" here or the path will be doubled.
OPENROUTER_API_BASE_URL=https://openrouter.ai/api
OPENROUTER_HTTP_REFERER=https://ai-symfony.hellyer.test
OPENROUTER_TITLE=gitmeh

# Rate limiting
API_DAILY_LIMIT=1000
API_RATE_LIMIT_TIMEZONE=UTC
# Must already include the scheme, e.g. redis://localhost:6379
REDIS_URL=redis://localhost:6379

# Trusted proxies (comma-separated CIDRs, or "*" to trust all — see Phase 1.7)
TRUSTED_PROXIES=*

# Gitmeh hosted API
GITMEH_HOSTED_TOKEN=gitmeh-public-client
GITMEH_PROMPT="Write a Git commit message (Conventional Commits format) for this diff. Reply with ONLY the commit message. No analysis, no explanation, no preamble. Start with a verb. No numbering. No bullet points."
GITMEH_CHAT_INFERENCE_TIMEOUT=120
GITMEH_MAX_JSON_BYTES=2097152
```

Set your real `OPENROUTER_API_KEY` in `.env.local`.

> ⚠️ **Quote env values containing special characters.** The `GITMEH_PROMPT` value contains parentheses and commas; wrap it in double quotes so the shell and Symfony's env loader treat it as one string.

### 1.3 Configure `config/packages/ai.yaml`

The `symfony/ai-open-router-platform` bridge's `Factory` hardcodes the base URL as `https://openrouter.ai/api` (it appends `/v1/chat/completions` itself). The bundle's `openrouter` config schema (verified in `config/reference.php`) accepts **only** two keys: `api_key` and `http_client`. There is no `base_url`, `http_referer`, `x_title`, or `default_model` key on the platform.

```yaml
ai:
    platform:
        openrouter:
            api_key: '%env(OPENROUTER_API_KEY)%'
            http_client: 'openrouter.http_client'   # see Phase 1.7
```

The OpenRouter `HTTP-Referer` and `X-Title` headers (which the Laravel app sends for ranking/attribution) are **not** added by the bridge. To preserve that behavior, point `http_client` at a scoped client that injects the headers on every outbound request — see **Phase 1.7** below. (If you skip the headers, drop the `http_client` line and the bundle uses the default `http_client` service.)

The default model is **not** configured in `ai.yaml`; it's an app parameter in `gitmeh.yaml` (Phase 1.4) and passed explicitly to `PlatformInterface::invoke()`.

### 1.4 Create `config/packages/gitmeh.yaml`

Symfony doesn't have Laravel's nested config arrays by default, so create a dedicated config file.

**Important — env processor syntax:** Symfony's `env(default:fallback:VAR)` processor takes the fallback as a single literal argument. You **cannot** put a raw string containing colons or commas in the fallback position (the parser splits on `:`), and you **cannot** chain `env(default:120:int:VAR)` — the processors apply right-to-left and `default` must be outermost when the var is missing. For numeric/cast values use the `int`/`string` processors on the *resolved* value, not inside `default:`. The safest pattern for defaults is to keep the `.env` value authoritative and only use `default:` for simple, colon-free fallbacks.

```yaml
parameters:
    # OpenRouter-only: provider is always "openrouter" (kept as a param for logging/tests).
    gitmeh.provider: 'openrouter'
    gitmeh.default_model: '%env(default:google/gemma-3-4b-it:OPENROUTER_MODEL)%'
    gitmeh.prompt: '%env(GITMEH_PROMPT)%'
    gitmeh.chat_inference_timeout_seconds: '%env(int:GITMEH_CHAT_INFERENCE_TIMEOUT)%'
    gitmeh.timeout: '%env(int:GITMEH_CHAT_TIMEOUT)%'
    gitmeh.daily_limit: '%env(int:API_DAILY_LIMIT)%'
    gitmeh.timezone: '%env(default:UTC:API_RATE_LIMIT_TIMEZONE)%'
    gitmeh.hosted_bearer_token: '%env(default:gitmeh-public-client:GITMEH_HOSTED_TOKEN)%'
    gitmeh.max_json_request_bytes: '%env(int:GITMEH_MAX_JSON_BYTES)%'
```

> ⚠️ `env(int:VAR)` will throw a `Symfony\Component\DependencyInjection\ParameterNotFoundException` / runtime exception at boot if the var is undefined and there's no `default:` wrapper. Make sure every var listed above is present in `.env` (Phase 1.2 sets defaults for all of them), **or** wrap each one in `env(default:<value>:VAR)` first and cast in PHP instead. The example above assumes all vars are defined in `.env` as shown in 1.2. If you'd rather not define `GITMEH_CHAT_TIMEOUT` etc. in `.env`, use this safer form and cast in code:
>
> ```yaml
> gitmeh.timeout: '%env(default:120:GITMEH_CHAT_TIMEOUT)%'
> ```
> …and `(int) $this->timeout` in the service constructor.

### 1.5 Configure Redis in `config/packages/framework.yaml`

Add a Redis cache pool (only needed if you prefer Symfony's Cache abstraction over a raw client).

```yaml
framework:
    cache:
        pools:
            cache.gitmeh_rate_limit:
                adapter: cache.adapter.redis
                # REDIS_URL already includes the scheme (redis://...), so pass it through directly —
                # do NOT prefix with redis:// again or you'll get redis://redis://...
                provider: '%env(REDIS_URL)%'
```

**Recommendation:** Skip the pool and inject a `\Predis\Client` directly (Phase 1.6). The rate limiter uses Redis hashes and `HINCRBY`, which are easier with a raw client than Symfony's Cache abstraction (which has no first-class hash API).

### 1.6 Register `Predis\Client` as a service

Add to `config/services.yaml`. `REDIS_URL` already includes the scheme, so pass it straight to Predis without re-wrapping:

```yaml
services:
    Predis\Client:
        arguments:
            - '%env(REDIS_URL)%'
```

### 1.7 Trusted proxies & OpenRouter headers

Two things the Laravel app gets for free that Symfony needs explicit config for:

**a) Trusted proxies (so `getClientIp()` returns the real client IP).** The Laravel app calls `$middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'))`. Without this, behind a reverse proxy Symfony's `Request::getClientIp()` returns the proxy's IP, so every request shares one rate-limit bucket. Add to `config/packages/framework.yaml`:

```yaml
framework:
    trusted_proxies: '%env(default:127.0.0.1:TRUSTED_PROXIES)%'
    trusted_headers:
        - 'x-forwarded-for'
        - 'x-forwarded-host'
        - 'x-forwarded-proto'
        - 'x-forwarded-port'
        - 'x-forwarded-prefix'
```

(On Symfony 7.4 `trusted_proxies` accepts a comma-separated string or `*` to trust all.)

**b) OpenRouter `HTTP-Referer` / `X-Title` headers.** The `symfony/ai-open-router-platform` bridge does **not** send these (confirmed by reading the bridge's `Factory.php` — it only forwards the API key as a Bearer header). The Laravel app sends them for ranking/attribution. To preserve that, register a scoped HttpClient that injects the headers on every OpenRouter request, and point the platform at it. `ScopingHttpClient::forBaseUri($client, $baseUri, $defaultOptions)` takes the default options (including `headers`) as its **third** argument — note this is *not* the `defaultOptionsByRegexp` array form used by the constructor:

```yaml
# config/services.yaml
services:
    openrouter.http_client:
        class: Symfony\Component\HttpClient\ScopingHttpClient
        factory: ['Symfony\Component\HttpClient\ScopingHttpClient', 'forBaseUri']
        arguments:
            - '@http_client'
            - 'https://openrouter.ai/api'
            - headers:
                'HTTP-Referer': '%env(default:https://ai-symfony.hellyer.test:OPENROUTER_HTTP_REFERER)%'
                'X-Title': '%env(default:gitmeh:OPENROUTER_TITLE)%'
```

Then reference it in `ai.yaml` so the bundle passes it to the OpenRouter `Factory`:

```yaml
ai:
    platform:
        openrouter:
            api_key: '%env(OPENROUTER_API_KEY)%'
            http_client: 'openrouter.http_client'
```

> The `http_client` key takes a **service id string** (not the `$autowireAlias` form). The bundle passes that service to `Factory::createPlatform()` as `$httpClient`; the Factory then wraps it in an `EventSourceHttpClient`, which delegates `request()` to the scoped client — so the headers still apply.

---

## Phase 2: Core Service — Commit Message Generator

### 2.1 Create `src/Service/GitmehCommitMessageGenerator.php`

This replaces Laravel's `app/Services/GitmehCommitMessageGenerator.php`. It uses `symfony/ai-bundle`'s `PlatformInterface` instead of raw HTTP calls.

**Key differences from the Laravel version:**

- Instead of building OpenAI-compatible HTTP requests manually, it calls `$this->platform->invoke($model, $messages)` where `$platform` is `PlatformInterface` injected via constructor.
- The bundle handles OpenRouter headers (`HTTP-Referer`, `X-Title`) automatically.
- Retry logic, fallback models, context-length detection, and timeout handling port directly.
- The `defaultInstruction()` method and model resolution logic are simplified since we only support OpenRouter.

```php
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
        // server default" — if we forwarded it to OpenRouter verbatim it would 404.
        // Normalise it to null before applying the default, matching Laravel behavior.
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
            'error' => "all " . count($models) . " models failed: {$errorMsg} (models: {$modelsList})",
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
            // NOTE: the Symfony AI Platform API uses Message::forSystem() for system
            // messages and Message::ofUser() for user messages. There is no
            // Message::ofSystem() — that was a guess and does not exist.
            $messages = new MessageBag(
                Message::forSystem($instruction),
                Message::ofUser("Unified diff:\n" . $diff),
            );

            // NOTE: the platform option is "max_output_tokens", NOT "max_tokens".
            // "max_tokens" is silently ignored by the generic completions client.
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
            'timeout', 'connection refused', 'no such host',
            'connection reset', 'TLS handshake',
            '429', '500', '502', '503', '504',
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
```

---

## Phase 3: Rate Limiting

### 3.1 Create `src/Service/GitmehHourlyLimiter.php`

This replaces Laravel's `app/Services/GitmehHourlyLimiter.php`. Uses `\Predis\Client` directly for Redis hash operations.

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Predis\Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GitmehHourlyLimiter
{
    private readonly string $timezone;
    private readonly int $dailyLimit;

    public function __construct(
        private readonly Client $redis,
        #[Autowire('%gitmeh.timezone%')]
        ?string $timezone = null,
        #[Autowire('%gitmeh.daily_limit%')]
        int $dailyLimit = 1000,
    ) {
        $this->timezone = $timezone ?? 'UTC';
        $this->dailyLimit = max(1, $dailyLimit);
    }

    public function dailyLimit(): int
    {
        return $this->dailyLimit;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function todayKey(): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
        return 'gitmeh:hits:' . $now->format('Y-m-d');
    }

    public function currentUsage(string $ip): int
    {
        $raw = $this->redis->hget($this->todayKey(), $ip);
        return $raw === null ? 0 : (int) $raw;
    }

    public function remaining(string $ip): int
    {
        return max(0, $this->dailyLimit - $this->currentUsage($ip));
    }

    public function attempt(string $ip): bool
    {
        $key = $this->todayKey();
        $count = (int) $this->redis->hincrby($key, $ip, 1);

        if ($count > $this->dailyLimit) {
            $this->redis->hincrby($key, $ip, -1);
            return false;
        }

        return true;
    }

    public function retryAfterSeconds(): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->timezone));
        $end = $now->setTime(23, 59, 59);
        $diff = $end->getTimestamp() - $now->getTimestamp();
        return max(1, $diff);
    }

    /**
     * @return array<string, int>
     */
    public function statusForIp(string $ip): array
    {
        return [
            'used' => $this->currentUsage($ip),
            'remaining' => $this->remaining($ip),
            'limit' => $this->dailyLimit,
            'timezone' => $this->timezone,
        ];
    }
}
```

---

## Phase 4: Middleware Equivalents (Event Subscribers)

Symfony doesn't have Laravel-style middleware. Use **event subscribers** on `kernel.controller` and `kernel.request` events.

### 4.1 Rate limit enforcement — `src/EventSubscriber/RateLimitSubscriber.php`

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\GitmehHourlyLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly GitmehHourlyLimiter $limiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 70],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $method = $request->getMethod();

        // IMPORTANT: Laravel only rate-limits POST /gitmeh and POST /v1/chat/completions.
        // GET /gitmeh is the read-only status page and must NOT consume quota.
        // The earlier draft matched str_starts_with($path, '/gitmeh'), which would have
        // burned a daily request on every status-page load — a real bug.
        $isRateLimited =
            ($path === '/gitmeh' && $method === 'POST')
            || ($path === '/v1/chat/completions' && $method === 'POST');

        if (!$isRateLimited) {
            return;
        }

        $ip = $request->getClientIp() ?? '127.0.0.1';

        if (!$this->limiter->attempt($ip)) {
            $retryAfter = (string) $this->limiter->retryAfterSeconds();
            $isChatCompletions = $path === '/v1/chat/completions';

            if ($isChatCompletions) {
                $response = new JsonResponse([
                    'error' => [
                        'message' => 'Daily API limit reached for your IP.',
                        'type' => 'rate_limit_error',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => $retryAfter,
                ]);
            } else {
                $response = new Response(
                    'Too Many Requests: daily API limit reached for your IP.',
                    Response::HTTP_TOO_MANY_REQUESTS,
                    [
                        'Content-Type' => 'text/plain; charset=UTF-8',
                        'Retry-After' => $retryAfter,
                    ]
                );
            }

            $event->setResponse($response);
        }
    }
}
```

### 4.2 JSON body size limit — `src/EventSubscriber/JsonBodySizeSubscriber.php`

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class JsonBodySizeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%gitmeh.max_json_request_bytes%')]
        private readonly int $maxBytes,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 80],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($event->getRequest()->getPathInfo() !== '/v1/chat/completions') {
            return;
        }

        $contentLength = (int) ($event->getRequest()->headers->get('Content-Length', '0'));
        if ($contentLength > $this->maxBytes) {
            $event->setResponse(new JsonResponse([
                'error' => [
                    'message' => 'Request body exceeds maximum allowed size.',
                    'type' => 'invalid_request_error',
                    'code' => 'request_too_large',
                ],
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE));
        }
    }
}
```

### 4.3 POST-only enforcement — `src/EventSubscriber/ChatCompletionsPostOnlySubscriber.php`

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ChatCompletionsPostOnlySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($event->getRequest()->getPathInfo() !== '/v1/chat/completions') {
            return;
        }

        if ($event->getRequest()->getMethod() !== 'POST') {
            $event->setResponse(new JsonResponse([
                'error' => [
                    'message' => 'Method not allowed. Use POST.',
                    'type' => 'invalid_request_error',
                    'code' => 'method_not_allowed',
                ],
            ], Response::HTTP_METHOD_NOT_ALLOWED, [
                // Laravel sends Allow: POST on its 405; match it.
                'Allow' => 'POST',
            ]));
        }
    }
}
```

### 4.4 Optional Bearer token validation — `src/EventSubscriber/OptionalBearerSubscriber.php`

```php
<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class OptionalBearerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%gitmeh.hosted_bearer_token%')]
        private readonly string $hostedToken,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 90],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($event->getRequest()->getPathInfo() !== '/v1/chat/completions') {
            return;
        }

        $header = $event->getRequest()->headers->get('Authorization');

        if ($header === null || $header === '') {
            return; // No auth — allowed, use hosted key
        }

        if (!str_starts_with($header, 'Bearer ')) {
            $event->setResponse($this->unauthorized('Invalid Authorization scheme.'));
            return;
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            $event->setResponse($this->unauthorized('Invalid bearer token.'));
            return;
        }

        if (!hash_equals($this->hostedToken, $token)) {
            // Non-hosted token: forward as client API key
            $event->getRequest()->attributes->set('gitmeh_client_api_key', $token);
        }
        // If it matches the hosted token, do nothing special — use server's own key
    }

    private function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => $message,
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
```

**Event subscriber priority order (higher = runs first):**

| Priority | Subscriber | Purpose |
|---|---|---|
| 100 | `ChatCompletionsPostOnlySubscriber` | Reject non-POST (405) — must run before rate limiting so a 405 doesn't burn quota |
| 90 | `OptionalBearerSubscriber` | Validate Bearer (401) — must run before rate limiting so a 401 doesn't burn quota |
| 80 | `JsonBodySizeSubscriber` | Reject oversized bodies (413) — must run before rate limiting so a 413 doesn't burn quota |
| 70 | `RateLimitSubscriber` | Increment + enforce daily per-IP limit LAST, only after all validation passes |

**Why this order matters:** the Laravel middleware stack is `chat_post_only → json_body → optional_bearer → daily`. Validation middleware runs *before* the rate-limit middleware, so rejected requests (405/401/413) never call `attempt()` and never consume a daily slot. The first draft reversed this (rate limit at priority 100), which meant a malformed request with a bad token would still increment the IP's counter — a real regression versus Laravel. The corrected order above matches Laravel's behavior exactly: only a request that passes all validation and actually reaches the controller counts against the limit.

The subscribers are auto-registered by Symfony's service autoconfiguration (they implement `EventSubscriberInterface`).

---

## Phase 5: Controllers

### 5.1 Update `src/Controller/GitmehController.php`

Replace the current stubs with the real implementation:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GitmehCommitMessageGenerator;
use App\Service\GitmehHourlyLimiter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GitmehController extends AbstractController
{
    public function __construct(
        private readonly GitmehCommitMessageGenerator $generator,
        private readonly GitmehHourlyLimiter $limiter,
    ) {
    }

    #[Route('/gitmeh', name: 'gitmeh_get', methods: ['GET'])]
    public function get(Request $request): Response
    {
        $ip = $request->getClientIp() ?? '127.0.0.1';
        $status = $this->limiter->statusForIp($ip);

        return $this->json([
            'ip' => $ip,
            ...$status,
        ]);
    }

    #[Route('/gitmeh', name: 'gitmeh_post', methods: ['POST'])]
    public function post(Request $request): Response
    {
        $diff = $request->getContent();
        $instruction = $this->generator->defaultInstruction();

        $result = $this->generator->generate($instruction, $diff);

        if (!$result['ok']) {
            return new Response(
                $result['error'],
                $result['status'],
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }

        return new Response(
            $result['message'],
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }
}
```

### 5.2 Create `src/Controller/GitmehChatCompletionsController.php`

This is the OpenAI-compatible `POST /v1/chat/completions` endpoint:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GitmehCommitMessageGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GitmehChatCompletionsController extends AbstractController
{
    private const UNIFIED_DIFF_PREFIX = "Unified diff:\n";
    private const UNIFIED_DIFF_PREFIX_CR = "Unified diff:\r\n";

    public function __construct(
        private readonly GitmehCommitMessageGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/chat/completions', name: 'chat_completions', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $started = microtime(true);

        $raw = $request->getContent();

        if ($raw === '') {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        if (!is_array($data)) {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        // Validate optional fields
        if (array_key_exists('model', $data) && !is_string($data['model'])) {
            return $this->errorResponse('Field "model" must be a string.', 'invalid_request_error', 'invalid_model', 400);
        }

        if (array_key_exists('fallback_models', $data) && (!is_array($data['fallback_models']) || count(array_filter($data['fallback_models'], 'is_string')) !== count($data['fallback_models']))) {
            return $this->errorResponse('Field "fallback_models" must be an array of strings.', 'invalid_request_error', 'invalid_fallback_models', 400);
        }

        // Validate messages
        if (!isset($data['messages']) || !is_array($data['messages'])) {
            return $this->errorResponse('Field "messages" is required and must be an array.', 'invalid_request_error', 'missing_messages', 400);
        }

        $systemParts = [];
        $lastUserContent = null;

        foreach ($data['messages'] as $index => $message) {
            if (!is_array($message)) {
                return $this->errorResponse("messages[{$index}] must be an object.", 'invalid_request_error', 'invalid_messages', 400);
            }
            $role = $message['role'] ?? null;
            if (!is_string($role)) {
                return $this->errorResponse("messages[{$index}].role is required.", 'invalid_request_error', 'invalid_messages', 400);
            }
            $content = $message['content'] ?? null;
            if ($role === 'system') {
                if (!is_string($content)) {
                    return $this->errorResponse("messages[{$index}].content must be a string.", 'invalid_request_error', 'invalid_messages', 400);
                }
                if ($content !== '') {
                    $systemParts[] = $content;
                }
            }
            if ($role === 'user') {
                if (!is_string($content)) {
                    return $this->errorResponse("messages[{$index}].content must be a string.", 'invalid_request_error', 'invalid_messages', 400);
                }
                $lastUserContent = $content;
            }
        }

        if ($lastUserContent === null) {
            return $this->errorResponse('At least one user message is required.', 'invalid_request_error', 'missing_user_message', 400);
        }

        $diff = $this->stripUnifiedDiffPrefix($lastUserContent);
        if (trim($diff) === '') {
            return $this->errorResponse('User message content is empty after extracting the diff.', 'invalid_request_error', 'empty_diff', 400);
        }

        $instruction = $systemParts !== []
            ? implode("\n\n", $systemParts)
            : $this->generator->defaultInstruction();

        $clientApiKey = $request->attributes->get('gitmeh_client_api_key');
        $model = is_string($data['model'] ?? null) ? $data['model'] : null;
        $fallbackModels = array_filter(
            (array) ($data['fallback_models'] ?? []),
            'is_string'
        );

        // If a client API key is provided, we need to use it instead of the server's key.
        // This requires creating a PlatformInterface with the client's key.
        // TODO: If client API key support is needed, inject a PlatformFactory that
        // creates a PlatformInterface with a custom API key. For now, we use the
        // server's configured key. The Laravel app forwarded client keys directly
        // in raw HTTP calls — with the symfony/ai-bundle abstraction, this needs
        // a factory pattern. See Phase 6 below.

        $result = $this->generator->generate(
            instruction: $instruction,
            unifiedDiff: $diff,
            model: $model,
            fallbackModels: $fallbackModels,
        );

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $this->logger->info('gitmeh.chat_completions', [
            'status' => $result['ok'] ? 200 : $result['status'],
            'latency_ms' => $latencyMs,
        ]);

        if (!$result['ok']) {
            return $this->errorResponse($result['error'], 'api_error', 'inference_error', $result['status']);
        }

        if ($result['message'] === '') {
            return $this->errorResponse('Model returned an empty commit message.', 'api_error', 'empty_content', 502);
        }

        return new JsonResponse([
            'id' => 'chatcmpl-gitmeh-' . bin2hex(random_bytes(8)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $result['model'] ?? (is_string($data['model'] ?? null) ? $data['model'] : 'gitmeh-hosted'),
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $result['message'],
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);
    }

    private function stripUnifiedDiffPrefix(string $content): string
    {
        if (str_starts_with($content, self::UNIFIED_DIFF_PREFIX)) {
            return substr($content, strlen(self::UNIFIED_DIFF_PREFIX));
        }
        if (str_starts_with($content, self::UNIFIED_DIFF_PREFIX_CR)) {
            return substr($content, strlen(self::UNIFIED_DIFF_PREFIX_CR));
        }
        return $content;
    }

    private function errorResponse(string $message, string $type, string $code, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'message' => $message,
                'type' => $type,
                'code' => $code,
            ],
        ], $status);
    }
}
```

### 5.3 Update `config/routes.yaml`

No changes needed — the `#[Route]` attributes on the controllers are auto-loaded by `routes.yaml`'s existing `routing.controllers` import.

---

## Phase 6: Client API Key Support (Optional)

The Laravel app supports clients bringing their own OpenRouter API key via the `Authorization: Bearer <key>` header (when it doesn't match the hosted token). With `symfony/ai-bundle`, the `PlatformInterface` is configured once with the server's key. To support per-request client keys, build a fresh platform per request using the OpenRouter bridge's `Factory` (verified class name & signature against the bridge source).

### 6.1 Create `src/Service/PlatformFactory.php`

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Bridge\OpenRouter\Factory;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlatformFactory
{
    public function __construct(
        private readonly PlatformInterface $defaultPlatform,
        private readonly HttpClientInterface $openRouterHttpClient,
        private readonly string $baseUrl = 'https://openrouter.ai/api',
    ) {
    }

    public function createWithApiKey(string $apiKey): PlatformInterface
    {
        // Factory::createPlatform() signature (from the bridge source):
        //   createPlatform(
        //       string $apiKey,
        //       ?HttpClientInterface $httpClient = null,
        //       ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        //       ?Contract $contract = null,
        //       ?EventDispatcherInterface $eventDispatcher = null,
        //       string $name = 'openrouter',
        //       ?ModelRouterInterface $modelRouter = null,
        //       string $baseUrl = 'https://openrouter.ai/api',
        //   ): Platform
        // There is NO httpReferer/xTitle parameter — those come from the scoped
        // HttpClient ($openRouterHttpClient), which is reused here so per-request
        // client-key platforms still send the attribution headers.
        return Factory::createPlatform(
            apiKey: $apiKey,
            httpClient: $this->openRouterHttpClient,
            baseUrl: $this->baseUrl,
        );
    }

    public function getDefault(): PlatformInterface
    {
        return $this->defaultPlatform;
    }
}
```

Then modify `GitmehCommitMessageGenerator::generate()` to accept an optional `?PlatformInterface $platform = null` argument and use it instead of `$this->platform` when provided, so the controller can pass a client-key-backed platform through. Only implement this if client API key forwarding is needed.

**For the initial build, skip this phase.** Implement after the basic setup (Phases 1–5) is verified working.

---

## Phase 7: Scheduled Task (Daily Hit Flush)

The Laravel app archives yesterday's Redis hit counts to SQLite via `api:flush-daily-hits`. In Symfony, this is a console command + cron/messenger schedule.

### 7.1 Create `src/Command/FlushDailyApiHitsCommand.php`

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GitmehHourlyLimiter;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'api:flush-daily-hits')]
final class FlushDailyApiHitsCommand extends Command
{
    public function __construct(
        private readonly GitmehHourlyLimiter $limiter,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tz = $this->limiter->timezone();
        $yesterday = (new \DateTimeImmutable('yesterday', new \DateTimeZone($tz)))->format('Y-m-d');
        $key = 'gitmeh:hits:' . $yesterday;

        // Redis interaction via the limiter's Redis client
        // The limiter doesn't expose the Redis client directly, so add a method to
        // GitmehHourlyLimiter or inject Predis\Client here too.

        // For simplicity, inject Predis\Client directly:
        // $this->redis->hgetall($key) etc.

        $output->writeln("Flushed hits for {$yesterday}");

        return Command::SUCCESS;
    }
}
```

**Note:** The exact Redis archiving logic depends on how much of the Laravel functionality you want to port. The Laravel version persists to SQLite. In Symfony, you could persist to PostgreSQL (the app already uses it). Create a Doctrine entity + migration for `ApiIpDailyHit` if this archival matters.

### 7.2 Scheduling

Add a cron entry on the server:

```
5 0 * * * /path/to/ai-symfony.hellyer.test/bin/console api:flush-daily-hits
```

Or use Symfony's Messenger scheduler with `symfony/scheduler` if preferred.

---

## Phase 8: Test Controller & Verification

### 8.1 Smoke test

After all phases are complete, create a temporary smoke test controller to verify the OpenRouter connection works:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AiTestController extends AbstractController
{
    #[Route('/ai-test', name: 'ai_test')]
    public function test(PlatformInterface $platform): Response
    {
        $messages = new MessageBag(
            Message::ofUser('Say hello in exactly 5 words.')
        );

        // Use a model that OpenRouter actually routes. "openai/gpt-4o-mini" is an
        // OpenRouter model id (provider/model), not a direct OpenAI call.
        $result = $platform->invoke('openai/gpt-4o-mini', $messages);

        return new Response($result->asText());
    }
}
```

### 8.2 Verify

```bash
# Test the LLM smoke test
curl -k https://ai-symfony.hellyer.test/ai-test

# Test the gitmeh GET (status)
curl -k https://ai-symfony.hellyer.test/gitmeh

# Test the gitmeh POST (legacy)
echo "diff --git a/file.txt b/file.txt" | curl -k -X POST https://ai-symfony.hellyer.test/gitmeh --data-binary @-

# Test the chat completions endpoint
curl -k https://ai-symfony.hellyer.test/v1/chat/completions \
  -H "Content-Type: application/json" \
  -d '{
    "model": "google/gemma-3-4b-it",
    "messages": [
      {"role": "system", "content": "Reply with only the word hello."},
      {"role": "user", "content": "Unified diff:\n+hello world"}
    ]
  }'
```

### 8.3 Remove smoke test

Delete `src/Controller/AiTestController.php` after confirming everything works.

---

## File Summary

| File | Action | Purpose |
|---|---|---|
| `composer.json` | Modified (by Composer) | New dependencies |
| `config/packages/ai.yaml` | **Create** | OpenRouter platform config (api key, base url, scoped http client) |
| `config/packages/gitmeh.yaml` | **Create** | App-specific parameters |
| `config/packages/framework.yaml` | **Modify** | Trusted proxies + (optional) Redis cache pool |
| `config/services.yaml` | **Modify** | Register `Predis\Client` + scoped `openRouterHttpClient` |
| `.env` | **Modify** | Add env vars |
| `.env.local` | **Modify** | Set real `OPENROUTER_API_KEY` |
| `src/Service/GitmehCommitMessageGenerator.php` | **Create** | Core LLM service |
| `src/Service/GitmehHourlyLimiter.php` | **Create** | Redis rate limiter |
| `src/EventSubscriber/RateLimitSubscriber.php` | **Create** | Rate limit enforcement (POST-only) |
| `src/EventSubscriber/JsonBodySizeSubscriber.php` | **Create** | JSON body size check |
| `src/EventSubscriber/ChatCompletionsPostOnlySubscriber.php` | **Create** | POST-only enforcement |
| `src/EventSubscriber/OptionalBearerSubscriber.php` | **Create** | Bearer token handling |
| `src/Controller/GitmehController.php` | **Replace** | GET status + POST legacy |
| `src/Controller/GitmehChatCompletionsController.php` | **Create** | OpenAI-compatible API |
| `src/Command/FlushDailyApiHitsCommand.php` | **Create** | Daily hit archival |

## What's NOT Ported (OpenRouter-Only Simplifications)

- **Multi-provider support:** Only OpenRouter. No config for OpenAI, Anthropic, Groq, Mistral, etc.
- **`config/ai.php` multi-provider config:** Replaced by `ai.yaml` (bundle config) + `gitmeh.yaml` (app params).
- **`provider` field in chat completions:** Ignored. Only OpenRouter is used.
- **`GITMEH_PROVIDER` env var:** Removed. Always OpenRouter.
- **`config/gitmeh.php` `default_provider`:** Removed. Hardcoded to OpenRouter.
- **Hardcoded provider fallback defaults in `resolveBaseUrl()` / `resolveModel()`:** Removed. Only OpenRouter model/URL configuration remains.
- **`config/app.php` fallback for `HTTP-Referer` / `X-Title`:** Replaced by explicit `OPENROUTER_HTTP_REFERER` and `OPENROUTER_TITLE` env vars.
