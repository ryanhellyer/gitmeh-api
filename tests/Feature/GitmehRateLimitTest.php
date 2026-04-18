<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\GitmehDailyApiLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Ai\AnonymousAgent;
use Tests\TestCase;

/**
 * This tests the rate limiting and how it interfaces with Redis.
 */
class GitmehRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gitmeh.daily_limit' => 3,
            'ai.providers.openrouter.key' => 'sk-test-key',
        ]);

        AnonymousAgent::fake(array_fill(0, 20, 'commit message'));

        try {
            Redis::connection()->flushdb();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis is not available.');
        }
    }

    public function test_post_gitmeh_returns_429_after_daily_limit(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->call('POST', '/gitmeh', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'diff')
                ->assertOk();
        }

        $this->call('POST', '/gitmeh', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'diff')
            ->assertStatus(429);
    }

    public function test_get_gitmeh_does_not_increment_usage(): void
    {
        $limiter = app(GitmehDailyApiLimiter::class);
        $before = $limiter->currentUsage('127.0.0.1');

        $this->get('/gitmeh')->assertOk();

        $this->assertSame($before, $limiter->currentUsage('127.0.0.1'));
    }
}
