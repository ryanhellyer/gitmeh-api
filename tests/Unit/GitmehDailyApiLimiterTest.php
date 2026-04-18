<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GitmehDailyApiLimiter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class GitmehDailyApiLimiterTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** The configured limit never drops below one; zero or negative becomes 1. */
    public function test_daily_limit_never_below_one(): void
    {
        config(['gitmeh.daily_limit' => 0]);

        $limiter = app(GitmehDailyApiLimiter::class);

        $this->assertSame(1, $limiter->dailyLimit());
    }

    /** Redis keys group hits by calendar day using an ISO date in the key name. */
    public function test_day_hash_key_uses_iso_date(): void
    {
        $limiter = app(GitmehDailyApiLimiter::class);
        $day = CarbonImmutable::parse('2026-04-18', 'UTC');

        $this->assertSame('gitmeh:hits:2026-04-18', $limiter->dayHashKey($day));
    }

    /** If no API timezone is set, we use the app default (same rule as production). */
    public function test_timezone_falls_back_to_app_timezone(): void
    {
        config([
            'gitmeh.timezone' => null,
            'app.timezone' => 'Europe/Berlin',
        ]);

        $limiter = app(GitmehDailyApiLimiter::class);

        $this->assertSame('Europe/Berlin', $limiter->timezone());
    }

    /** Retry-After is the seconds from “now” until end of day in the limiter timezone. */
    public function test_retry_after_matches_seconds_until_end_of_day(): void
    {
        config(['gitmeh.timezone' => 'UTC']);
        $fixed = CarbonImmutable::parse('2026-04-18 14:30:00', 'UTC');
        CarbonImmutable::setTestNow($fixed);

        $limiter = app(GitmehDailyApiLimiter::class);
        $expected = (int) $fixed->diffInSeconds($fixed->endOfDay());

        $this->assertSame($expected, $limiter->retryAfterSeconds());
    }
}
