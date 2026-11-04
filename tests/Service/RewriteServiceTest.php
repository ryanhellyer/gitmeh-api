<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RewriteService;
use App\Service\TextOffsets;
use App\Tests\Double\RecordingModelRunner;
use PHPUnit\Framework\TestCase;

final class RewriteServiceTest extends TestCase
{
    public function testPromptIncludesTheRequestedStyle(): void
    {
        $prompt = RewriteService::prompt('German', 'formal');

        self::assertStringContainsString('rewriting text in German', $prompt);
        self::assertStringContainsString('Formal register', $prompt);
        self::assertStringContainsString('UTF-16', $prompt);
        self::assertStringContainsString('"rewrite"', $prompt);
        self::assertStringNotContainsString('Plain language', $prompt);
    }

    public function testNaturalPromptHasNoNamedStyle(): void
    {
        $prompt = RewriteService::prompt('German', null);

        self::assertStringContainsString('Natural, idiomatic register', $prompt);
        self::assertStringNotContainsString('Formal register', $prompt);
        self::assertStringNotContainsString('Playful', $prompt);
    }

    public function testRewriteParsesFencedJsonAndRecomputesBadOffsets(): void
    {
        $rewrite = '😀 Ich habe das sehr gut gemacht.';
        $offset = TextOffsets::find($rewrite, 'sehr gut');
        self::assertNotNull($offset);

        $payload = json_encode([
            'rewrite' => $rewrite,
            'changes' => [[
                // Deliberately wrong offsets; replacement is the source of truth.
                'start' => $offset - 2,
                'end' => $offset + 3,
                'original' => 'sehr gut',
                'replacement' => 'sehr gut',
                'note' => 'A stronger phrase.',
            ]],
        ], JSON_THROW_ON_ERROR);

        $runner = new RecordingModelRunner("```json\n{$payload}\n```");

        $result = (new RewriteService($runner, 'vendor/review-model'))
            ->rewrite('German', 'Ich habe das gut gemacht.', 'playful');

        self::assertTrue($result['ok']);
        self::assertSame($rewrite, $result['rewrite']);
        self::assertSame($offset, $result['changes'][0]['start']);
        self::assertSame($offset + 8, $result['changes'][0]['end']);
        self::assertSame('A stronger phrase.', $result['changes'][0]['note']);
        self::assertSame('vendor/review-model', $result['model']);

        self::assertCount(1, $runner->calls);
        self::assertSame(0.4, $runner->calls[0]['options']['temperature']);
        self::assertSame('Ich habe das gut gemacht.', $runner->calls[0]['user']);
        self::assertStringContainsString('Playful', $runner->calls[0]['system']);
    }

    public function testRewriteDropsChangesThatCannotBePlaced(): void
    {
        $payload = json_encode([
            'rewrite' => 'Ein guter Tag.',
            'changes' => [
                ['start' => 999, 'end' => 1000, 'replacement' => 'nirgendwo'],
                ['start' => 4, 'end' => 9, 'replacement' => 'guter'],
                ['start' => 3, 'end' => 4, 'replacement' => ''],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = (new RewriteService(new RecordingModelRunner($payload), 'model'))
            ->rewrite('German', 'Ein guter Tag.');

        self::assertTrue($result['ok']);
        self::assertCount(1, $result['changes']);
        self::assertSame(4, $result['changes'][0]['start']);
        self::assertSame(9, $result['changes'][0]['end']);
        self::assertArrayNotHasKey('original', $result['changes'][0]);
    }

    public function testMissingOrEmptyRewriteBecomes502(): void
    {
        foreach (['{}', '{"rewrite":""}', '{"rewrite":"   "}', '{"rewrite":42}', 'not json'] as $reply) {
            $result = (new RewriteService(new RecordingModelRunner($reply), 'model'))
                ->rewrite('German', 'Hallo');

            self::assertFalse($result['ok'], $reply);
            self::assertSame(502, $result['status'], $reply);
        }
    }

    public function testModelFailureIsPassedThrough(): void
    {
        $result = (new RewriteService(new RecordingModelRunner('unused', false), 'model'))
            ->rewrite('German', 'Hallo');

        self::assertFalse($result['ok']);
        self::assertSame(502, $result['status']);
        self::assertSame('upstream exploded', $result['error']);
    }
}
