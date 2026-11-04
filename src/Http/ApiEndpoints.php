<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Canonical paths for the JSON API.
 *
 * The request subscribers (POST-only guard, body-size cap, bearer check, rate
 * limiter) key off these constants, so adding an endpoint is one edit here
 * instead of four separate string comparisons.
 */
final class ApiEndpoints
{
    public const CHAT_COMPLETIONS = '/v1/chat/completions';
    public const TRANSLATIONS = '/v1/translations';
    public const ANALYSES = '/v1/analyses';
    public const REWRITES = '/v1/rewrites';

    /**
     * Paths that only accept POST, speak JSON, and consume the shared hourly quota.
     */
    public const JSON_POST_PATHS = [
        self::CHAT_COMPLETIONS,
        self::TRANSLATIONS,
        self::ANALYSES,
        self::REWRITES,
    ];

    public static function isJsonPostPath(string $path): bool
    {
        return in_array($path, self::JSON_POST_PATHS, true);
    }
}
