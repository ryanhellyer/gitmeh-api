<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\RewritesController;
use App\Service\RewriteService;
use App\Tests\Double\RecordingModelRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class RewritesControllerTest extends TestCase
{
    private RecordingModelRunner $runner;

    private RewritesController $controller;

    protected function setUp(): void
    {
        $this->runner = new RecordingModelRunner('{"rewrite":"Hallo Welt","changes":[]}');
        $this->controller = new RewritesController(
            new RewriteService($this->runner, 'test/review-model'),
            new NullLogger(),
        );
    }

    public function testHappyPathPassesTheStyle(): void
    {
        $payload = json_encode([
            'rewrite' => 'Guten Tag, Welt!',
            'changes' => [[
                'start' => 0,
                'end' => 9,
                'original' => 'Hallo Welt',
                'replacement' => 'Guten Tag',
                'note' => 'More formal.',
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->runner = new RecordingModelRunner($payload);
        $this->controller = new RewritesController(
            new RewriteService($this->runner, 'test/review-model'),
            new NullLogger(),
        );

        $response = $this->post([
            'language' => 'German',
            'text' => 'Hallo Welt',
            'style' => 'formal',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('Guten Tag, Welt!', $body['rewrite']);
        self::assertCount(1, $body['changes']);
        self::assertSame('Guten Tag', $body['changes'][0]['replacement']);

        self::assertCount(1, $this->runner->calls);
        self::assertStringContainsString('Formal register', $this->runner->calls[0]['system']);
        self::assertSame('Hallo Welt', $this->runner->calls[0]['user']);
    }

    public function testOmittedOrNaturalStyleMeansNatural(): void
    {
        foreach ([
            ['language' => 'German', 'text' => 'Hallo Welt'],
            ['language' => 'German', 'text' => 'Hallo Welt', 'style' => null],
            ['language' => 'German', 'text' => 'Hallo Welt', 'style' => 'natural'],
            ['language' => 'German', 'text' => 'Hallo Welt', 'style' => ' NATURAL '],
        ] as $payload) {
            $this->runner->calls = [];

            $response = $this->post($payload);

            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString('Natural, idiomatic register', $this->runner->calls[0]['system']);
        }
    }

    public function testChangesAreOptional(): void
    {
        $this->runner = new RecordingModelRunner('{"rewrite":"Hallo Welt"}');
        $this->controller = new RewritesController(
            new RewriteService($this->runner, 'test/review-model'),
            new NullLogger(),
        );

        $response = $this->post(['language' => 'German', 'text' => 'Hallo Welt']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->decode($response)['changes']);
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
        self::assertSame($expectedCode, $body['error']['code']);
        self::assertSame('invalid_request_error', $body['error']['type']);
        self::assertSame([], $this->runner->calls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidPayloads(): iterable
    {
        $valid = ['language' => 'German', 'text' => 'Hallo Welt'];

        yield 'empty body' => ['', 'invalid_json'];
        yield 'malformed json' => ['{"language":', 'invalid_json'];
        yield 'json list' => ['[1,2]', 'invalid_json'];

        yield 'missing language' => [['language' => null] + $valid, 'invalid_language'];
        yield 'unknown language' => [['language' => 'Martian'] + $valid, 'invalid_language'];

        yield 'missing text' => [['text' => null] + $valid, 'invalid_text'];
        yield 'blank text' => [['text' => '   '] + $valid, 'invalid_text'];
        yield 'non-string text' => [['text' => 42] + $valid, 'invalid_text'];
        yield 'overlong text' => [['text' => str_repeat('a', 5001)] + $valid, 'text_too_long'];

        yield 'unknown style' => [['style' => 'shouty'] + $valid, 'invalid_style'];
        yield 'non-string style' => [['style' => ['formal']] + $valid, 'invalid_style'];
    }

    public function testModelFailureBecomes502(): void
    {
        $this->controller = new RewritesController(
            new RewriteService(new RecordingModelRunner('unused', false), 'test/review-model'),
            new NullLogger(),
        );

        $response = $this->post(['language' => 'German', 'text' => 'Hallo Welt']);

        self::assertSame(502, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('rewrite_error', $body['error']['code']);
        self::assertStringContainsString('upstream exploded', $body['error']['message']);
    }

    public function testInvalidModelResponseBecomes502(): void
    {
        $this->controller = new RewritesController(
            new RewriteService(new RecordingModelRunner('{"rewrite":""}'), 'test/review-model'),
            new NullLogger(),
        );

        $response = $this->post(['language' => 'German', 'text' => 'Hallo Welt']);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('rewrite_error', $this->decode($response)['error']['code']);
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    private function post(array|string $payload): JsonResponse
    {
        $content = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/v1/rewrites',
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
