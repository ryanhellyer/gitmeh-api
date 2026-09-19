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
 * Optional Bearer token validation for /v1/chat/completions.
 *
 * Missing Authorization is allowed (same rate limits apply). If a Bearer token is
 * present it must match the hosted token, otherwise it is forwarded as a client
 * API key (gitmeh_client_api_key request attribute) so the controller can use the
 * caller's own OpenRouter key. Runs before rate limiting so a 401 doesn't burn quota.
 */
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
            return; // No auth — allowed, use the server's hosted key.
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
            // Non-hosted token: store it for the controller to forward downstream.
            $event->getRequest()->attributes->set('gitmeh_client_api_key', $token);
        }
        // If it matches the hosted token, do nothing special — use the server's key.
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
