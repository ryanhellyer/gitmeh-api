<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TextOffsets;
use PHPUnit\Framework\TestCase;

final class TextOffsetsTest extends TestCase
{
    public function testLengthCountsUtf16Units(): void
    {
        self::assertSame(0, TextOffsets::length(''));
        self::assertSame(5, TextOffsets::length('Hallo'));
        self::assertSame(6, TextOffsets::length('Müller'));
        self::assertSame(4, TextOffsets::length('A😀B'));
        self::assertSame(2, TextOffsets::length('😀'));
    }

    public function testSliceUsesUtf16Offsets(): void
    {
        $text = 'A😀B';

        self::assertSame('A', TextOffsets::slice($text, 0, 1));
        self::assertSame('😀', TextOffsets::slice($text, 1, 3));
        self::assertSame('B', TextOffsets::slice($text, 3, 4));
        self::assertSame('A😀B', TextOffsets::slice($text, 0, 4));
    }

    public function testSliceRejectsSpansThatSplitASurrogatePair(): void
    {
        self::assertNull(TextOffsets::slice('A😀B', 1, 2));
        self::assertNull(TextOffsets::slice('A😀B', 2, 3));
    }

    public function testSliceRejectsEmptyAndOutOfRangeSpans(): void
    {
        self::assertNull(TextOffsets::slice('Hallo', 2, 2));
        self::assertNull(TextOffsets::slice('Hallo', 3, 2));
        self::assertNull(TextOffsets::slice('Hallo', 0, 6));
        self::assertNull(TextOffsets::slice('Hallo', -1, 2));
    }

    public function testFindReturnsUtf16OffsetAndPrefersTheHint(): void
    {
        $text = 'Ich bin hier. Ich bin dort.';

        self::assertSame(0, TextOffsets::find($text, 'Ich bin'));
        self::assertSame(14, TextOffsets::find($text, 'Ich bin', 13));
        self::assertSame(14, TextOffsets::find($text, 'Ich bin', 12));
        self::assertNull(TextOffsets::find($text, 'nirgendwo'));
        self::assertNull(TextOffsets::find($text, ''));
    }

    public function testFindCountsAstralCharactersAsTwoUnits(): void
    {
        $text = '😀 letzte Woche';

        self::assertSame(3, TextOffsets::find($text, 'letzte Woche'));
    }
}
