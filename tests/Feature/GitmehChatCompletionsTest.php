<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\GitmehDailyApiLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
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
            'gitmeh.default_provider' => 'openrouter',
            'ai.providers.openrouter.key' => 'sk-test-key',
            'ai.providers.openrouter.url' => 'https://openrouter.ai/api/v1',
        ]);

        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Add bar to foo',
                        ],
                    ],
                ],
            ], 200),
        ]);

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
            ->assertJsonPath('choices.0.message.content', 'Add bar to foo')
            ->assertJsonPath('model', 'google/gemma-3-4b-it');
    }

    public function test_gitmeh_hosted_model_resolved_to_provider_default(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'model' => 'gitmeh-hosted',
            'messages' => [
                ['role' => 'user', 'content' => "Unified diff:\n--- a/foo\n+++ b/foo\n@@ -0,0 +1 @@\n+baz\n"],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertOk()
            ->assertJsonPath('model', 'google/gemma-3-4b-it');
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

    public function test_invalid_auth_scheme_returns_401(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz',
        ], $this->validPayload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_empty_bearer_returns_401(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ',
        ], $this->validPayload())
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_custom_bearer_is_forwarded_to_provider(): void
    {
        Http::fake([
            'openrouter.ai/*' => function (\Illuminate\Http\Client\Request $request) {
                $this->assertSame('Bearer my-custom-key', $request->header('Authorization')[0]);

                return Http::response([
                    'choices' => [
                        ['message' => ['role' => 'assistant', 'content' => 'custom key msg']],
                    ],
                ], 200);
            },
        ]);

        config(['gitmeh.hosted_bearer_token' => 'gitmeh-public-client']);

        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer my-custom-key',
        ], json_encode([
            'model' => 'custom-model',
            'messages' => [
                ['role' => 'user', 'content' => 'diff content'],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertOk()
            ->assertJsonPath('choices.0.message.content', 'custom key msg');
    }

    public function test_provider_field_selects_upstream_provider(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => 'openai response']],
                ],
            ], 200),
        ]);

        config([
            'ai.providers.openai.key' => 'sk-openai-test',
            'ai.providers.openai.url' => 'https://api.openai.com/v1',
        ]);

        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'provider' => 'openai',
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'user', 'content' => 'diff content'],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertOk()
            ->assertJsonPath('choices.0.message.content', 'openai response');
    }

    public function test_fallback_models_are_tried_on_context_length_error(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push(['error' => ['message' => 'context length exceeded']], 400)
                ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'fallback worked']]]], 200),
        ]);

        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'model' => 'primary-model',
            'fallback_models' => ['fallback-model'],
            'messages' => [
                ['role' => 'user', 'content' => 'diff content'],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertOk()
            ->assertJsonPath('choices.0.message.content', 'fallback worked');
    }

    public function test_empty_model_text_returns_502_json(): void
    {
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => '']],
                ],
            ], 200),
        ]);

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

    public function test_unknown_provider_returns_400(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'provider' => 'nonexistent',
            'messages' => [
                ['role' => 'user', 'content' => 'diff content'],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'inference_error');
    }

    public function test_invalid_provider_type_returns_400(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'provider' => 123,
            'messages' => [
                ['role' => 'user', 'content' => 'diff'],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_provider');
    }

    public function test_invalid_fallback_models_type_returns_400(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'fallback_models' => 'not-an-array',
            'messages' => [
                ['role' => 'user', 'content' => 'diff'],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_fallback_models');
    }

    public function test_invalid_fallback_models_element_type_returns_400(): void
    {
        $this->call('POST', '/v1/chat/completions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'fallback_models' => [123],
            'messages' => [
                ['role' => 'user', 'content' => 'diff'],
            ],
        ], JSON_THROW_ON_ERROR))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'invalid_fallback_models');
    }
}
