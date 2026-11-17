<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AnalysisService;
use App\Service\TextOffsets;
use App\Tests\Double\RecordingModelRunner;
use PHPUnit\Framework\TestCase;

final class AnalysisServiceTest extends TestCase
{
    public function testPromptNamesTheLanguageExplanationLanguageAndChecks(): void
    {
        $prompt = AnalysisService::prompt('German', 'English', ['grammar', 'style']);

        self::assertStringContainsString('learners of German', $prompt);
        self::assertStringContainsString('grammar, style', $prompt);
        self::assertStringContainsString('in English', $prompt);
        self::assertStringContainsString('in German', $prompt);
        self::assertStringContainsString('UTF-16', $prompt);
        self::assertStringContainsString('"issues"', $prompt);
        self::assertStringNotContainsString('spelling', $prompt);
    }

    public function testAnalyseParsesFencedJsonAndRecomputesBadOffsets(): void
    {
        $text = '😀 Ich bin nach Berlin gereist letzte Woche.';
        $offset = TextOffsets::find($text, 'letzte Woche');
        self::assertNotNull($offset);

        $payload = json_encode([
            'issues' => [[
                // Deliberately wrong (code-point) offsets; the fragment is the
                // source of truth and the span should be repaired.
                'start' => $offset - 2,
                'end' => $offset + 10,
                'check' => 'grammar',
                'severity' => 'correction',
                'category' => 'Word order',
                'original' => 'letzte Woche',
                'replacement' => 'letzte Woche',
                'sentence' => 'Ich bin nach Berlin gereist letzte Woche.',
                'corrected' => 'Ich bin letzte Woche nach Berlin gereist.',
                'explanation' => 'Time before place.',
                'tip' => 'Ask wann before wohin.',
                'examples' => [['correct' => 'Wir fahren morgen.', 'wrong' => 'Wir fahren morgen nach.']],
            ]],
            'corrected_text' => '😀 Ich bin letzte Woche nach Berlin gereist.',
        ], JSON_THROW_ON_ERROR);

        $runner = new RecordingModelRunner("```json\n{$payload}\n```");

        $result = (new AnalysisService($runner, 'vendor/review-model'))
            ->analyse('German', 'English', $text);

        self::assertTrue($result['ok']);
        self::assertSame($offset, $result['issues'][0]['start']);
        self::assertSame($offset + 12, $result['issues'][0]['end']);
        self::assertSame('letzte Woche', $result['issues'][0]['original']);
        self::assertSame('😀 Ich bin letzte Woche nach Berlin gereist.', $result['corrected_text']);
        self::assertSame('vendor/review-model', $result['model']);

        self::assertCount(1, $runner->calls);
        self::assertSame('vendor/review-model', $runner->calls[0]['model']);
        self::assertSame(0.2, $runner->calls[0]['options']['temperature']);
        self::assertSame($text, $runner->calls[0]['user']);
    }

    public function testAnalyseDropsUnplaceableAndUnrequestedIssuesAndOverlaps(): void
    {
        $text = 'Ich bin nach Berlin gereist letzte Woche.';
        $offset = TextOffsets::find($text, 'letzte Woche');
        self::assertNotNull($offset);

        $payload = json_encode([
            'issues' => [
                [
                    'start' => 999,
                    'end' => 1000,
                    'check' => 'grammar',
                    'original' => 'nicht im Text',
                    'explanation' => 'nowhere',
                ],
                [
                    'start' => $offset,
                    'end' => $offset + 12,
                    'check' => 'style',
                    'original' => 'letzte Woche',
                    'replacement' => 'letzte Woche',
                    'explanation' => 'style only',
                ],
                [
                    'start' => $offset,
                    'end' => $offset + 12,
                    'check' => 'grammar',
                    'original' => 'letzte Woche',
                    'replacement' => 'letzte Woche',
                    'explanation' => 'kept',
                ],
                [
                    'start' => $offset + 2,
                    'end' => $offset + 12,
                    'check' => 'grammar',
                    'original' => 'tzte Woche',
                    'replacement' => 'x',
                    'explanation' => 'overlaps the earlier issue',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $result = (new AnalysisService(new RecordingModelRunner($payload), 'model'))
            ->analyse('German', 'English', $text, ['grammar']);

        self::assertTrue($result['ok']);
        self::assertCount(1, $result['issues']);
        self::assertSame('kept', $result['issues'][0]['explanation']);
        self::assertNull($result['corrected_text']);
    }

    public function testIssueWithoutExplanationStillGetsAnEmptyExplanation(): void
    {
        $text = 'Hallo Welt';
        $payload = json_encode([
            'issues' => [[
                'start' => 0,
                'end' => 5,
                'original' => 'Hallo',
                'replacement' => 'Hallo!',
            ]],
        ], JSON_THROW_ON_ERROR);

        $result = (new AnalysisService(new RecordingModelRunner($payload), 'model'))
            ->analyse('German', 'English', $text);

        self::assertTrue($result['ok']);
        self::assertSame('', $result['issues'][0]['explanation']);
        self::assertArrayNotHasKey('category', $result['issues'][0]);
    }

    public function testUnfindableOriginalFallsBackToTheRealFragment(): void
    {
        $payload = json_encode([
            'issues' => [[
                'start' => 0,
                'end' => 5,
                'original' => 'Guten Tag',
                'replacement' => 'Hallo!',
            ]],
        ], JSON_THROW_ON_ERROR);

        $result = (new AnalysisService(new RecordingModelRunner($payload), 'model'))
            ->analyse('German', 'English', 'Hallo Welt');

        self::assertTrue($result['ok']);
        self::assertSame('Hallo', $result['issues'][0]['original']);
        self::assertSame('Hallo!', $result['issues'][0]['replacement']);
    }

    public function testInvalidJsonBecomes502(): void
    {
        $result = (new AnalysisService(new RecordingModelRunner('I could not do that.'), 'model'))
            ->analyse('German', 'English', 'Hallo');

        self::assertFalse($result['ok']);
        self::assertSame(502, $result['status']);
        self::assertStringContainsString('invalid analysis response', $result['error']);
    }

    public function testMissingOrNonArrayIssuesBecomes502(): void
    {
        foreach ([
            '{"corrected_text":"Hallo"}',
            '{"issues":"nope"}',
            '{"issues":{"first":{"start":0,"end":1}}}',
        ] as $reply) {
            $result = (new AnalysisService(new RecordingModelRunner($reply), 'model'))
                ->analyse('German', 'English', 'Hallo');

            self::assertFalse($result['ok'], $reply);
            self::assertSame(502, $result['status'], $reply);
        }
    }

    public function testModelFailureIsPassedThrough(): void
    {
        $result = (new AnalysisService(new RecordingModelRunner('unused', false), 'model'))
            ->analyse('German', 'English', 'Hallo');

        self::assertFalse($result['ok']);
        self::assertSame(502, $result['status']);
        self::assertSame('upstream exploded', $result['error']);
    }
}
