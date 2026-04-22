<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token is optional: missing header is allowed (same rate limits).
 * If {@see Authorization} is present, it must be a matching {@see Bearer} token.
 */
final class ValidateOptionalGitmehHostedBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path() !== 'v1/chat/completions') {
            return $next($request);
        }

        $header = $request->header('Authorization');
        if ($header === null || $header === '') {
            return $next($request);
        }

        $expected = (string) config('gitmeh.hosted_bearer_token', 'gitmeh-public-client');
        $prefix = 'Bearer ';
        if (! str_starts_with($header, $prefix)) {
            return $this->unauthorized('Invalid Authorization scheme.');
        }

        $token = trim(substr($header, strlen($prefix)));
        if ($token === '' || ! hash_equals($expected, $token)) {
            return $this->unauthorized('Invalid bearer token.');
        }

        return $next($request);
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], 401);
    }
}
