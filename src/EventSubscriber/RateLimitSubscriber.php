<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\GitmehDailyApiLimiter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces the per-IP daily rate limit. Runs LAST (priority 70), after the
 * PostOnly / Bearer / JsonBodySize checks, so only requests that pass all
 * validation and reach the controller count against the daily quota — matching
 * the Laravel middleware order (chat_post_only → json_body → optional_bearer → daily).
 *
 * IMPORTANT: only POST /gitmeh and POST /v1/chat/completions consume quota.
 * GET /gitmeh is the read-only status page and must NOT be rate limited.
 */
final class RateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly GitmehDailyApiLimiter $limiter,
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
