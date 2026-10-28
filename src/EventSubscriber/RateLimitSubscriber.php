<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\ApiHourlyLimiter;
use App\Service\Limit;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces the per-IP hourly rate limit. Runs LAST (priority 70), after the
 * PostOnly / Bearer / JsonBodySize checks, so only requests that pass all
 * validation and reach the controller count against the quota — matching the
 * Laravel middleware order (chat_post_only → json_body → optional_bearer → daily).
 *
 * Only POST /gitmeh and the JSON API endpoints (/v1/chat/completions,
 * /v1/translations) consume quota. Each has its own per-IP bucket and ceiling
 * (see Limit), so exhausting one never limits the others.
 * GET /gitmeh is the read-only status page and is never rate limited.
 */
final class RateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApiHourlyLimiter $limiter,
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

        // Only POST consumes quota — GET /gitmeh is the free status page. A path
        // that is not a limited endpoint (forPostPath returns null) is skipped.
        if ($method !== 'POST') {
            return;
        }

        $limit = Limit::forPostPath($path);
        if ($limit === null) {
            return;
        }

        $ip = $request->getClientIp() ?? '127.0.0.1';

        if (!$this->limiter->attempt($limit, $ip)) {
            $remaining = $this->limiter->remaining($limit, $ip);
            $retryAfter = (string) $this->limiter->retryAfterSeconds();
            $limitValue = $this->limiter->hourlyLimit($limit);
            $isJsonEndpoint = $limit->isJsonEndpoint();

            if ($isJsonEndpoint) {
                // OpenAI-shaped error so OpenAI-compatible clients surface it cleanly.
                $response = new JsonResponse([
                    'error' => [
                        'message' => "You have reached the hourly limit of {$limitValue} requests. Please try again in {$retryAfter} seconds.",
                        'type' => 'rate_limit_error',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => (string) $limitValue,
                    'X-RateLimit-Remaining' => (string) $remaining,
                    'X-RateLimit-Reset' => $retryAfter,
                ]);
            } else {
                $response = new Response(
                    "Too Many Requests: you have reached the hourly limit of {$limitValue} requests. Please try again in {$retryAfter} seconds.",
                    Response::HTTP_TOO_MANY_REQUESTS,
                    [
                        'Content-Type' => 'text/plain; charset=UTF-8',
                        'Retry-After' => $retryAfter,
                        'X-RateLimit-Limit' => (string) $limitValue,
                        'X-RateLimit-Remaining' => (string) $remaining,
                    ]
                );
            }

            $event->setResponse($response);
        }
    }
}
