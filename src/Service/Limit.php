<?php

declare(strict_types=1);

namespace App\Service;

use App\Http\ApiEndpoints;

/**
 * A rate-limit bucket: one per endpoint family, so one endpoint's traffic never
 * consumes another's quota.
 *
 * Each case carries the Redis bucket name; the hourly ceiling for each bucket is
 * configured per environment via env vars (see config/packages/gitmeh.yaml) and
 * resolved by ApiHourlyLimiter.
 */
enum Limit: string
{
    case GITMEH = 'gitmeh';
    case TRANSLATIONS = 'translations';
    case CHAT_COMPLETIONS = 'chat_completions';

    /**
     * Redis bucket name, e.g. "gitmeh" in api:hits:gitmeh:2026-10-28-23.
     */
    public function bucket(): string
    {
        return $this->value;
    }

    /**
     * The bucket a request path consumes, or null when the path is not limited.
     *
     * POST /gitmeh is the legacy commit-message endpoint; the /v1 paths are the
     * JSON API. GET /gitmeh (the status page) is deliberately absent — reading
     * status must never cost quota.
     */
    public static function forPostPath(string $path): ?self
    {
        return match ($path) {
            ApiEndpoints::TRANSLATIONS => self::TRANSLATIONS,
            ApiEndpoints::CHAT_COMPLETIONS => self::CHAT_COMPLETIONS,
            '/gitmeh' => self::GITMEH,
            default => null,
        };
    }

    /**
     * Human-readable label for the status page and error text.
     */
    public function label(): string
    {
        return match ($this) {
            self::GITMEH => 'gitmeh',
            self::TRANSLATIONS => 'translations',
            self::CHAT_COMPLETIONS => 'chat completions',
        };
    }

    /**
     * True for the OpenAI-compatible JSON endpoints, which answer a 429 with an
     * OpenAI-shaped error body. POST /gitmeh answers plain text instead.
     */
    public function isJsonEndpoint(): bool
    {
        return $this !== self::GITMEH;
    }
}
