<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceGitmehDailyLimit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));

        $middleware->validateCsrfTokens(except: [
            'gitmeh',
            'gitmeh/*',
        ]);

        $middleware->alias([
            'gitmeh.daily' => EnforceGitmehDailyLimit::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('api:flush-daily-hits')->dailyAt('00:05');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
