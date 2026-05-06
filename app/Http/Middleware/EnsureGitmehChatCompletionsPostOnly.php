<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects non-POST /v1/chat/completions before rate limiting or body reads.
 */
final class EnsureGitmehChatCompletionsPostOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path() === 'v1/chat/completions' && ! $request->isMethod('POST')) {
            return response()->json([
                'error' => [
                    'message' => 'Method not allowed.',
                    'type' => 'invalid_request_error',
                    'code' => 'method_not_allowed',
                ],
            ], 405, ['Allow' => 'POST']);
        }

        return $next($request);
    }
}
