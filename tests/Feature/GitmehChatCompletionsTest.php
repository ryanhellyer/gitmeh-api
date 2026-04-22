<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\GitmehDailyApiLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Ai\AnonymousAgent;
use Tests\TestCase;

class GitmehChatCompletionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'gitmeh.daily_limit' => 100,
            'gitmeh.max_json_request_bytes' => 4096,
            'gitmeh.hosted_bearer_token' => 'gitmeh-public-client',
            'gitmeh.chat_inference_timeout_seconds' => 20,
            'ai.providers.openrouter.key' => 'sk-test-key',
        ]);

        AnonymousAgent::fake(array_fill(0, 50, 'Add bar to foo'));

        try {
            Redis::connection()->flushdb();
        } catch (\Throwable) {
            $this->markTestSkipped('Redis is not available.');
        }
    }

    private function validPayload(): string
    {
        return json_encode([
            'model' => 'gitmeh-hosted',
            'messages' => [
                ['role' => 'system', 'content' => 'Write a commit message.'],
                ['role' => 'user', 'content' => "Unified diff:\n--- a/foo\n+++ b/foo\n@@ -0,0 +1 @@\n+bar\n"],
            ],
            'temperature' => 0.3,
            'max_tokens' => 512,
        ], JSON_THROW_ON_ERROR);
    }

    public function test_post_chat_completions_returns_json_choice(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer gitmeh-public-client',
            'HTTP_ACCEPT' => 'application/json',
        ], $this->validPayload())
            ->assertOk()
            ->assertJsonPath('choices.0.message.role', 'assistant')
            ->assertJsonPath('choices.0.message.content', 'Add bar to foo');
    }

    public function test_missing_messages_returns_400_json(): void
    {
        $payload = json_encode(['model' => 'gitmeh-hosted'], JSON_THROW_ON_ERROR);

        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'missing_messages');
    }

    public function test_malformed_json_returns_400(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], '{not json')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_json');
    }

    public function test_oversized_body_returns_413(): void
    {
        config(['gitmeh.max_json_request_bytes' => 10]);

        $big = str_repeat('a', 50);
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $big)
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'request_too_large');
    }

    public function test_get_returns_405_without_json_body_middleware_side_effects(): void
    {
        $limiter = app(GitmehDailyApiLimiter::class);
        $before = $limiter->currentUsage('127.0.0.1');

        $this->get('/v1/chat/completions')
            ->assertStatus(405)
            ->assertHeader('Allow', 'POST')
            ->assertJsonPath('error.code', 'method_not_allowed');

        $this->assertSame($before, $limiter->currentUsage('127.0.0.1'));
    }

    public function test_wrong_bearer_returns_401(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ], $this->validPayload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_empty_model_text_returns_502_json(): void
    {
        AnonymousAgent::fake(['']);

        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $this->validPayload())
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'inference_error');
    }

    public function test_v1_shares_daily_limit_with_legacy_gitmeh(): void
    {
        config(['gitmeh.daily_limit' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', '/v1/chat/completions', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], $this->validPayload())->assertOk();
        }

        $this->call('POST', '/gitmeh', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'diff')
            ->assertStatus(429);
    }
}
