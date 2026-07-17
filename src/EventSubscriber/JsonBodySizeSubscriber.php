<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rejects JSON bodies larger than the configured maximum before parsing.
 * Runs before rate limiting so a 413 doesn't consume a daily quota slot.
 */
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
