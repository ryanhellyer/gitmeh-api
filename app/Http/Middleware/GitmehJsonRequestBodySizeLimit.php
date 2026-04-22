<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects JSON bodies larger than the configured maximum before parsing.
 */
final class GitmehJsonRequestBodySizeLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path() !== 'v1/chat/completions') {
            return $next($request);
        }

        $max = (int) config('gitmeh.max_json_request_bytes', 2_097_152);
        $contentLength = $request->header('Content-Length');
        if ($contentLength !== null && $contentLength !== '' && (int) $contentLength > $max) {
            return $this->tooLargeResponse();
        }

        return $next($request);
    }

    private function tooLargeResponse(): Response
    {
        return response()->json([
            'error' => [
                'message' => 'Request body exceeds maximum allowed size.',
                'type' => 'invalid_request_error',
                'code' => 'request_too_large',
            ],
        ], 413);
    }
}
