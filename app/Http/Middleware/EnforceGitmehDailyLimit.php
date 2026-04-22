<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\GitmehDailyApiLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceGitmehDailyLimit
{
    public function __construct(
        protected GitmehDailyApiLimiter $limiter
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        if (! $this->limiter->attempt($ip)) {
            $retryAfter = (string) $this->limiter->retryAfterSeconds();

            if ($request->path() === 'v1/chat/completions') {
                return response()->json([
                    'error' => [
                        'message' => 'Daily API limit reached for your IP.',
                        'type' => 'rate_limit_error',
                        'code' => 'rate_limit_exceeded',
                    ],
                ], 429, [
                    'Content-Type' => 'application/json',
                    'Retry-After' => $retryAfter,
                ]);
            }

            return response(
                'Too Many Requests: daily API limit reached for your IP.',
                429,
                [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Retry-After' => $retryAfter,
                ]
            );
        }

        return $next($request);
    }
}
