<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared helpers for the JSON API controllers.
 *
 * Error bodies keep the same OpenAI-shaped envelope as /v1/translations so the
 * existing clients and smoke tests see one consistent format.
 */
final class JsonRequest
{
    /**
     * Decode a JSON object body, or null when the body is absent, malformed,
     * or a list/scalar instead of an object.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(Request $request): ?array
    {
        $raw = $request->getContent();

        if ($raw === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // {} decodes to [], so only a non-empty list is a wrong shape.
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return null;
        }

        return $data;
    }

    public static function error(string $message, string $type, string $code, int $status): JsonResponse
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
