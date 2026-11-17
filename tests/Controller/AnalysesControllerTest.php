<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AnalysesController;
use App\Service\AnalysisService;
use App\Tests\Double\RecordingModelRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AnalysesControllerTest extends TestCase
{
    private RecordingModelRunner $runner;

    private AnalysesController $controller;

    protected function setUp(): void
    {
        $this->runner = new RecordingModelRunner('{"issues":[],"corrected_text":""}');
        $this->controller = new AnalysesController(
            new AnalysisService($this->runner, 'test/review-model'),
            new NullLogger(),
        );
    }

    public function testHappyPath(): void
    {
        $payload = json_encode([
            'issues' => [[
                'start' => 0,
                'end' => 5,
                'check' => 'grammar',
                'severity' => 'correction',
                'category' => 'Case',
                'original' => 'Hallo',
                'replacement' => 'Hallo!',
                'explanation' => 'Add a greeting.',
            ]],
            'corrected_text' => 'Hallo! Welt',
        ], JSON_THROW_ON_ERROR);

        $this->runner = new RecordingModelRunner($payload);
        $this->controller = new AnalysesController(
            new AnalysisService($this->runner, 'test/review-model'),
            new NullLogger(),
        );

        $response = $this->post([
            'language' => 'German',
            'explanation_language' => 'English',
            'text' => 'Hallo Welt',
            'checks' => ['grammar', 'style'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertCount(1, $body['issues']);
        self::assertSame('Hallo!', $body['issues'][0]['replacement']);
        self::assertSame('Hallo! Welt', $body['corrected_text']);

        self::assertCount(1, $this->runner->calls);
        self::assertStringContainsString('learners of German', $this->runner->calls[0]['system']);
        self::assertStringContainsString('in English', $this->runner->calls[0]['system']);
        self::assertStringContainsString('grammar, style', $this->runner->calls[0]['system']);
        self::assertSame('Hallo Welt', $this->runner->calls[0]['user']);
    }

    public function testChecksMayBeOmitted(): void
    {
        $response = $this->post([
            'language' => 'German',
            'explanation_language' => 'English',
            'text' => 'Hallo Welt',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('grammar, spelling, punctuation, style', $this->runner->calls[0]['system']);
    }

    public function testLanguageNamesAreNormalisedToTheCatalogueSpelling(): void
    {
        $response = $this->post([
            'language' => '  german ',
            'explanation_language' => 'ENGLISH',
            'text' => 'Hallo Welt',
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('learners of German', $this->runner->calls[0]['system']);
        self::assertStringContainsString('in English', $this->runner->calls[0]['system']);
    }

    public function testEmptyIssuesAreValid(): void
    {
        $response = $this->post([
            'language' => 'German',
            'explanation_language' => 'English',
            'text' => 'Hallo Welt',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame([], $body['issues']);
        self::assertArrayNotHasKey('corrected_text', $body);
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
        self::assertNotSame('', $body['error']['message']);
        self::assertSame([], $this->runner->calls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string, string}>
     */
    public static function invalidPayloads(): iterable
    {
        $valid = [
            'language' => 'German',
            'explanation_language' => 'English',
            'text' => 'Hallo Welt',
        ];

        yield 'empty body' => ['', 'invalid_json'];
        yield 'malformed json' => ['{"language":', 'invalid_json'];
        yield 'json list' => ['[1,2]', 'invalid_json'];
        yield 'json scalar' => ['"hello"', 'invalid_json'];

        yield 'missing language' => [['language' => null] + $valid, 'invalid_language'];
        yield 'unknown language' => [['language' => 'Martian'] + $valid, 'invalid_language'];
        yield 'detect language' => [['language' => 'Detect language'] + $valid, 'invalid_language'];
        yield 'non-string language' => [['language' => 42] + $valid, 'invalid_language'];

        yield 'missing explanation language' => [['explanation_language' => null] + $valid, 'invalid_explanation_language'];
        yield 'unknown explanation language' => [['explanation_language' => 'Martian'] + $valid, 'invalid_explanation_language'];

        yield 'missing text' => [['text' => null] + $valid, 'invalid_text'];
        yield 'blank text' => [['text' => "  \n "] + $valid, 'invalid_text'];
        yield 'non-string text' => [['text' => ['hi']] + $valid, 'invalid_text'];
        yield 'overlong text' => [['text' => str_repeat('a', 5001)] + $valid, 'text_too_long'];

        yield 'checks not a list' => [['checks' => 'grammar'] + $valid, 'invalid_checks'];
        yield 'unknown check' => [['checks' => ['grammar', 'handwriting']] + $valid, 'invalid_checks'];
        yield 'non-string check' => [['checks' => [42]] + $valid, 'invalid_checks'];
    }

    public function testTheLimitCountsUtf16UnitsNotCodePoints(): void
    {
        $response = $this->post([
            'language' => 'German',
            'explanation_language' => 'English',
            'text' => str_repeat('😀', 2501),
        ]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('text_too_long', $this->decode($response)['error']['code']);
        self::assertSame([], $this->runner->calls);
    }

    public function testModelFailureBecomes502(): void
    {
        $this->controller = new AnalysesController(
            new AnalysisService(new RecordingModelRunner('unused', false), 'test/review-model'),
            new NullLogger(),
        );

        $response = $this->post([
            'language' => 'German',
            'explanation_language' => 'English',
            'text' => 'Hallo Welt',
        ]);

        self::assertSame(502, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('analysis_error', $body['error']['code']);
        self::assertSame('api_error', $body['error']['type']);
        self::assertStringContainsString('upstream exploded', $body['error']['message']);
    }

    public function testInvalidModelResponseBecomes502(): void
    {
        $this->controller = new AnalysesController(
            new AnalysisService(new RecordingModelRunner('Sorry, I cannot.'), 'test/review-model'),
            new NullLogger(),
        );

        $response = $this->post([
            'language' => 'German',
            'explanation_language' => 'English',
            'text' => 'Hallo Welt',
        ]);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('analysis_error', $this->decode($response)['error']['code']);
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    private function post(array|string $payload): JsonResponse
    {
        $content = is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR);

        $request = Request::create(
            '/v1/analyses',
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
