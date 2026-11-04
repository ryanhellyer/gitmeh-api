<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Extracts and decodes the single JSON object a model was asked to return.
 *
 * Models often wrap the object in a Markdown fence or add a sentence before or
 * after it. Anything that is not a well-formed JSON object is rejected; the
 * caller answers with a structured 5xx rather than passing free text on as a
 * successful result.
 */
final class ModelJson
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $raw): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // Strip a leading UTF-8 byte-order mark.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        // Strip a fence that wraps the whole reply.
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $raw, $matches) === 1) {
            $raw = $matches[1];
        }

        $json = self::extractObject($raw);
        if ($json === null) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        // {} decodes to [], so an empty array is not a usable response either.
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            return null;
        }

        return $data;
    }

    /**
     * Return the first balanced {...} block, ignoring braces inside strings.
     */
    private static function extractObject(string $raw): ?string
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($raw);

        for ($i = $start; $i < $length; ++$i) {
            $character = $raw[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{') {
                ++$depth;
            } elseif ($character === '}') {
                --$depth;

                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
