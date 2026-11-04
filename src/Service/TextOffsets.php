<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Conversions between UTF-8 text and the UTF-16 code-unit offsets the browser
 * uses for string indices and highlight spans.
 *
 * An ASCII or BMP character is one unit; an emoji outside the BMP is two. Every
 * span this class produces starts and ends on a character boundary.
 */
final class TextOffsets
{
    /**
     * Length of a UTF-8 string in UTF-16 code units.
     */
    public static function length(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Slice a UTF-8 string by UTF-16 offsets.
     *
     * Returns null when the span is out of range, empty, or splits a surrogate
     * pair — all cases where a highlight cannot be drawn safely.
     */
    public static function slice(string $text, int $start, int $end): ?string
    {
        if ($start < 0 || $end <= $start || $end > self::length($text)) {
            return null;
        }

        $result = '';
        $cursor = 0;

        foreach (mb_str_split($text, 1, 'UTF-8') as $character) {
            $next = $cursor + self::width($character);

            if ($cursor >= $start && $next <= $end) {
                $result .= $character;
            } elseif ($next > $start && $cursor < $end) {
                // The span starts or ends inside a surrogate pair.
                return null;
            }

            $cursor = $next;

            if ($cursor >= $end) {
                break;
            }
        }

        return $cursor >= $end ? $result : null;
    }

    /**
     * Find a substring and return its UTF-16 offset.
     *
     * When a hint is given and the needle occurs more than once, the occurrence
     * closest to the hint wins — that is usually the span the model meant.
     */
    public static function find(string $text, string $needle, ?int $hint = null): ?int
    {
        if ($needle === '') {
            return null;
        }

        $positions = [];
        $offset = 0;

        while (($position = mb_strpos($text, $needle, $offset, 'UTF-8')) !== false) {
            $positions[] = self::length(mb_substr($text, 0, $position, 'UTF-8'));
            $offset = $position + 1;
        }

        if ($positions === []) {
            return null;
        }

        if ($hint === null) {
            return $positions[0];
        }

        $best = $positions[0];
        $bestDistance = abs($best - $hint);

        foreach ($positions as $position) {
            $distance = abs($position - $hint);
            if ($distance < $bestDistance) {
                $best = $position;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * UTF-16 width of one well-formed UTF-8 character (one or two units).
     */
    private static function width(string $character): int
    {
        return intdiv(strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')), 2);
    }
}
