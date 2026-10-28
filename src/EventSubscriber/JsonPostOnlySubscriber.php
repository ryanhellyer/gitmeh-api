<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Http\ApiEndpoints;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rejects non-POST requests to the JSON API endpoints with 405 before rate
 * limiting (so a 405 never consumes a daily quota slot — matches the Laravel
 * middleware order).
 */
final class JsonPostOnlySubscriber implements EventSubscriberInterface
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

        $request = $event->getRequest();

        if (!ApiEndpoints::isJsonPostPath($request->getPathInfo())) {
            return;
        }

        if ($request->getMethod() !== 'POST') {
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
