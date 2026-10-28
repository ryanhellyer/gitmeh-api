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
