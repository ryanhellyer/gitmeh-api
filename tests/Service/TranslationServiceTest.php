<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TranslationService;
use App\Tests\Double\RecordingModelRunner;
use PHPUnit\Framework\TestCase;

final class TranslationServiceTest extends TestCase
{
    public function testNormaliseToneAcceptsKnownValuesCaseInsensitively(): void
    {
        self::assertSame('kitten', TranslationService::normaliseTone('Kitten'));
        self::assertSame('simple', TranslationService::normaliseTone(' SIMPLE '));
        self::assertSame('informal', TranslationService::normaliseTone('Informal'));
    }

    public function testNormaliseToneCollapsesMissingValuesToNone(): void
    {
        self::assertSame(TranslationService::TONE_NONE, TranslationService::normaliseTone(null));
        self::assertSame(TranslationService::TONE_NONE, TranslationService::normaliseTone(''));
        self::assertSame(TranslationService::TONE_NONE, TranslationService::normaliseTone('   '));
        self::assertSame(TranslationService::TONE_NONE, TranslationService::normaliseTone('none'));
    }

    public function testNormaliseToneRejectsUnknownValues(): void
    {
        self::assertNull(TranslationService::normaliseTone('loud'));
        self::assertNull(TranslationService::normaliseTone('formal,simple'));
        self::assertNull(TranslationService::normaliseTone("\0invalid"));
    }

    public function testNeutralPromptContainsNoToneInstructionAtAll(): void
    {
        $prompt = TranslationService::prompt('English', 'French', TranslationService::TONE_NONE);

        self::assertStringContainsString('from English to French', $prompt);
        self::assertStringContainsString('Preserve meaning', $prompt);
        self::assertStringNotContainsString('Tone', $prompt);
        self::assertStringNotContainsString('register', $prompt);
        self::assertStringNotContainsString('kitten', $prompt);
    }

    public function testPromptRequiresTheSourceLineStructureToBeKeptExactly(): void
    {
        $prompt = TranslationService::prompt('English', 'German', TranslationService::TONE_NONE);

        self::assertStringContainsString('same number of lines in the same order', $prompt);
        self::assertStringContainsString('Never join lines, split lines, re-wrap, re-indent or tidy up the layout', $prompt);
        self::assertStringContainsString('even when the source is poorly formatted', $prompt);
    }

    public function testPromptStillPreservesFormattingWhenAToneIsChosen(): void
    {
        foreach (TranslationService::TONES as $tone) {
            $prompt = TranslationService::prompt('English', 'German', $tone);

            self::assertStringContainsString('Keep the exact layout of the source', $prompt, $tone);
            self::assertStringContainsString('Never join lines', $prompt, $tone);
        }
    }

    public function testTonePromptIncludesOnlyTheChosenInstruction(): void
    {
        $prompt = TranslationService::prompt('English', 'German', 'formal');

        self::assertStringContainsString('Tone: Formal register', $prompt);
        self::assertStringNotContainsString('kitten', $prompt);
        self::assertStringNotContainsString('Plain language', $prompt);
    }

    public function testKittenIsTheOnlyToneWithRaisedTemperature(): void
    {
        self::assertSame(0.7, TranslationService::temperatureFor('kitten'));
        self::assertSame(0.3, TranslationService::temperatureFor('formal'));
        self::assertSame(0.3, TranslationService::temperatureFor('informal'));
        self::assertSame(0.3, TranslationService::temperatureFor('simple'));
        self::assertSame(0.3, TranslationService::temperatureFor(TranslationService::TONE_NONE));
    }

    public function testTranslateUsesConfiguredModelAndKeepsTheWholeReply(): void
    {
        $runner = new RecordingModelRunner("line one\nline two\n");

        $result = (new TranslationService($runner, 'vendor/translation-model'))
            ->translate('English', 'French', "Hello\nthere", 'kitten');

        self::assertTrue($result['ok']);
        self::assertSame("line one\nline two", $result['translation']);
        self::assertSame('vendor/translation-model', $result['model']);

        self::assertCount(1, $runner->calls);
        self::assertSame('vendor/translation-model', $runner->calls[0]['model']);
        self::assertSame(0.7, $runner->calls[0]['options']['temperature']);
        self::assertSame(4096, $runner->calls[0]['options']['max_output_tokens']);
        self::assertStringContainsString('kitten', $runner->calls[0]['system']);
        self::assertStringContainsString("Hello\nthere", $runner->calls[0]['user']);
    }

    public function testTranslationKeepsPoorlyFormattedThreeLineInputIntact(): void
    {
        $source = "Hello there\nyes please\nok";
        $runner = new RecordingModelRunner("Bonjour toi\noui s'il te plait\nd'accord");

        $result = (new TranslationService($runner, 'vendor/translation-model'))
            ->translate('English', 'French', $source);

        self::assertTrue($result['ok']);
        self::assertSame("Bonjour toi\noui s'il te plait\nd'accord", $result['translation']);
        self::assertSame(3, substr_count($result['translation'], "\n") + 1);
        self::assertStringContainsString("Hello there\nyes please\nok", $runner->calls[0]['user']);
    }

    public function testTranslateWithNoToneSendsNoToneInstruction(): void
    {
        $runner = new RecordingModelRunner('Bonjour');

        $result = (new TranslationService($runner, 'vendor/translation-model'))
            ->translate('English', 'French', 'Hello');

        self::assertTrue($result['ok']);
        self::assertStringNotContainsString('Tone', $runner->calls[0]['system']);
        self::assertSame(0.3, $runner->calls[0]['options']['temperature']);
    }
}
