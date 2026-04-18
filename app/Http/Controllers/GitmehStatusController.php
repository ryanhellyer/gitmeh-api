<?php

namespace App\Http\Controllers;

use App\Services\GitmehDailyApiLimiter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GitmehStatusController extends Controller
{
    public function __construct(
        protected GitmehDailyApiLimiter $limiter
    ) {}

    public function __invoke(Request $request): View
    {
        $ip = $request->ip();

        return view('gitmeh-status', [
            'ip' => $ip,
            'used' => $this->limiter->currentUsage($ip),
            'remaining' => $this->limiter->remaining($ip),
            'limit' => $this->limiter->dailyLimit(),
            'timezone' => $this->limiter->timezone(),
        ]);
    }
}
