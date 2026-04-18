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
            return response(
                'Too Many Requests: daily API limit reached for your IP.',
                429,
                [
                    'Content-Type' => 'text/plain; charset=UTF-8',
                    'Retry-After' => (string) $this->limiter->retryAfterSeconds(),
                ]
            );
        }

        return $next($request);
    }
}
