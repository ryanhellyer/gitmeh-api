<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiIpDailyHit;
use App\Services\GitmehDailyApiLimiter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Best-effort flush archives only yesterday's Redis hash (rate-limit timezone), at most one key per call.
 */
class GitmehDailyApiFlushTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['gitmeh.timezone' => 'UTC']);

        try {
            Redis::connection()->flushdb();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis is not available.');
        }
    }

    public function test_flush_persists_yesterday_only_and_returns_one_or_zero(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-18 12:00:00', 'UTC'));

        $limiter = app(GitmehDailyApiLimiter::class);
        $yesterdayKey = 'gitmeh:hits:2026-04-17';
        $olderKey = 'gitmeh:hits:2026-04-01';

        Redis::connection()->hset($yesterdayKey, '203.0.113.10', '4');
        Redis::connection()->hset($olderKey, '203.0.113.99', '9');

        $this->assertSame(1, $limiter->flushCompletedDaysToDatabase());

        $this->assertDatabaseHas('api_ip_daily_hits', [
            'ip' => '203.0.113.10',
            'hit_count' => 4,
        ]);

        $row = ApiIpDailyHit::query()->where('ip', '203.0.113.10')->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-04-17', $row->day->format('Y-m-d'));

        $this->assertSame(0, (int) Redis::connection()->exists($yesterdayKey));
        $this->assertSame(1, (int) Redis::connection()->exists($olderKey));

        $this->assertSame(0, $limiter->flushCompletedDaysToDatabase());
    }

    public function test_flush_returns_zero_when_yesterday_key_missing(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-18 12:00:00', 'UTC'));

        $limiter = app(GitmehDailyApiLimiter::class);

        $this->assertSame(0, $limiter->flushCompletedDaysToDatabase());
    }
}
