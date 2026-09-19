<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\GitmehHourlyLimiter;
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
 * Only POST /gitmeh and POST /v1/chat/completions consume quota.
 * GET /gitmeh is the read-only status page and is never rate limited.
 */
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

        $isRateLimited =
            ($path === '/gitmeh' && $method === 'POST')
            || ($path === '/v1/chat/completions' && $method === 'POST');

        if (!$isRateLimited) {
            return;
        }

        $ip = $request->getClientIp() ?? '127.0.0.1';

        if (!$this->limiter->attempt($ip)) {
            $remaining = $this->limiter->remaining($ip);
            $retryAfter = (string) $this->limiter->retryAfterSeconds();
            $limit = $this->limiter->hourlyLimit();
            $isChatCompletions = $path === '/v1/chat/completions';

            if ($isChatCompletions) {
                // OpenAI-shaped error so OpenAI-compatible clients surface it cleanly.
                $response = new JsonResponse([
                    'error' => [
                        'message' => "You have reached the hourly limit of {$limit} requests. Please try again in {$retryAfter} seconds.",
                        'type' => 'rate_limit_error',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => (string) $limit,
                    'X-RateLimit-Remaining' => (string) $remaining,
                    'X-RateLimit-Reset' => $retryAfter,
                ]);
            } else {
                $response = new Response(
                    "Too Many Requests: you have reached the hourly limit of {$limit} requests. Please try again in {$retryAfter} seconds.",
                    Response::HTTP_TOO_MANY_REQUESTS,
                    [
                        'Content-Type' => 'text/plain; charset=UTF-8',
                        'Retry-After' => $retryAfter,
                        'X-RateLimit-Limit' => (string) $limit,
                        'X-RateLimit-Remaining' => (string) $remaining,
                    ]
                );
            }

            $event->setResponse($response);
        }
    }
}
