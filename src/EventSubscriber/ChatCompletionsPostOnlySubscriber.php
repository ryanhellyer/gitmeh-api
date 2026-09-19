<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rejects non-POST requests to /v1/chat/completions with 405 before rate limiting
 * (so a 405 never consumes a daily quota slot — matches the Laravel middleware order).
 */
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
                'Allow' => 'POST',
            ]));
        }
    }
}
