<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\TranslationsController;
use App\Service\TranslationService;
use App\Tests\Double\RecordingModelRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class TranslationsControllerTest extends TestCase
{
    private RecordingModelRunner $runner;

    private TranslationsController $controller;

    protected function setUp(): void
    {
        $this->runner = new RecordingModelRunner();
        $this->controller = new TranslationsController(
            new TranslationService($this->runner, 'test/translation-model'),
            new NullLogger(),
        );
    }

    public function testHappyPathWithTone(): void
    {
        $response = $this->post([
            'from' => 'English',
            'to' => 'French',
            'text' => 'Hello there',
            'tone' => 'Kitten',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('translated text', $body['translation']);
        self::assertSame('English', $body['from']);
        self::assertSame('French', $body['to']);
        self::assertSame('kitten', $body['tone']);
        self::assertSame('test/translation-model', $body['model']);

        self::assertCount(1, $this->runner->calls);
        self::assertSame('test/translation-model', $this->runner->calls[0]['model']);
        self::assertSame(0.7, $this->runner->calls[0]['options']['temperature']);
        self::assertStringContainsString('from English to French', $this->runner->calls[0]['system']);
        self::assertStringContainsString('kitten', $this->runner->calls[0]['system']);
        self::assertSame('Hello there', $this->runner->calls[0]['user']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('neutralTones')]
    public function testMissingToneMeansNoToneInstruction(array $payload): void
    {
        $response = $this->post($payload + [
            'from' => 'English',
            'to' => 'French',
            'text' => 'Hello there',
        ]);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('none', $body['tone']);

        self::assertCount(1, $this->runner->calls);
        self::assertStringNotContainsString('Tone', $this->runner->calls[0]['system']);
        self::assertSame(0.3, $this->runner->calls[0]['options']['temperature']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function neutralTones(): iterable
    {
        yield 'absent' => [[]];
        yield 'null' => [['tone' => null]];
        yield 'empty string' => [['tone' => '']];
        yield 'literal none' => [['tone' => 'NONE']];
    }

    public function testLanguagesAreTrimmed(): void
    {
        $response = $this->post([
            'from' => '  English ',
            'to' => " French\n",
            'text' => 'Hello',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('English', $body['from']);
        self::assertSame('French', $body['to']);
        self::assertStringContainsString('from English to French', $this->runner->calls[0]['system']);
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    #[DataProvider('invalidPayloads')]
    public function testInvalidRequestsAreRejectedBeforeCallingTheModel(array|string $payload, string $expectedCode): void
    {
        $response = $this->post($payload);

        self::assertSame(400, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('error', array_key_first($body));
        self::assertSame($expectedCode, $body['error']['code']);
        self::assertSame('invalid_request_error', $body['error']['type']);
        self::assertNotSame('', $body['error']['message']);

        // A rejected request must never reach the platform: no quota, no spend.
        self::assertSame([], $this->runner->calls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidPayloads(): iterable
    {
        $valid = ['from' => 'English', 'to' => 'French', 'text' => 'Hello'];

        yield 'empty body' => ['', 'invalid_json'];
        yield 'malformed json' => ['{"from":', 'invalid_json'];
        yield 'json list' => ['[1,2]', 'invalid_json'];
        yield 'json scalar' => ['"hello"', 'invalid_json'];
        yield 'json true' => ['true', 'invalid_json'];

        $payload = $valid;
        unset($payload['from']);
        yield 'missing from' => [$payload, 'invalid_from'];

        yield 'null from' => [['from' => null] + $valid, 'invalid_from'];
        yield 'blank from' => [['from' => '   '] + $valid, 'invalid_from'];
        yield 'numeric from' => [['from' => '12345'] + $valid, 'invalid_from'];
        yield 'non-string from' => [['from' => 42] + $valid, 'invalid_from'];
        yield 'overlong from' => [['from' => str_repeat('a', 51)] + $valid, 'invalid_from'];

        $payload = $valid;
        unset($payload['to']);
        yield 'missing to' => [$payload, 'invalid_to'];

        yield 'empty to' => [['to' => ''] + $valid, 'invalid_to'];
        yield 'punctuation-only to' => [['to' => '!!!'] + $valid, 'invalid_to'];

        $payload = $valid;
        unset($payload['text']);
        yield 'missing text' => [$payload, 'invalid_text'];

        yield 'blank text' => [['text' => "  \n "] + $valid, 'invalid_text'];
        yield 'non-string text' => [['text' => ['hi']] + $valid, 'invalid_text'];
        yield 'overlong text' => [['text' => str_repeat('a', 20001)] + $valid, 'text_too_long'];

        yield 'tone array' => [['tone' => ['formal']] + $valid, 'invalid_tone'];
        yield 'unknown tone' => [['tone' => 'loud'] + $valid, 'invalid_tone'];
        yield 'numeric tone' => [['tone' => 3] + $valid, 'invalid_tone'];
    }

    public function testModelFailureBecomes502(): void
    {
        $this->runner = new RecordingModelRunner('unused', false);
        $this->controller = new TranslationsController(
            new TranslationService($this->runner, 'test/translation-model'),
            new NullLogger(),
        );

        $response = $this->post([
            'from' => 'English',
            'to' => 'French',
            'text' => 'Hello',
        ]);

        self::assertSame(502, $response->getStatusCode());

        $body = $this->decode($response);
        self::assertSame('translation_error', $body['error']['code']);
        self::assertSame('api_error', $body['error']['type']);
        self::assertStringContainsString('upstream exploded', $body['error']['message']);
    }

    public function testEmptyModelReplyBecomes502(): void
    {
        $this->controller = new TranslationsController(
            new TranslationService(new RecordingModelRunner("  \n "), 'test/translation-model'),
            new NullLogger(),
        );

        $response = $this->post([
            'from' => 'English',
            'to' => 'French',
            'text' => 'Hello',
        ]);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('empty_translation', $this->decode($response)['error']['code']);
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    private function post(array|string $payload): JsonResponse
    {
        $content = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/v1/translations',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $content,
        );

        return ($this->controller)($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
