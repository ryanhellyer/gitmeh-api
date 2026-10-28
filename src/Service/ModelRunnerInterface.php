<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Message\MessageBag;

/**
 * Runs a chat-style inference with per-model retries and fallback models.
 *
 * Extracted from GitmehCommitMessageGenerator so the commit and translation
 * endpoints share identical retry/backoff/fallback behaviour.
 */
interface ModelRunnerInterface
{
    public const DEFAULT_MAX_RETRIES = 3;

    /**
     * @param array<string, mixed> $options        Platform options (temperature, max_output_tokens, …)
     * @param string[]             $fallbackModels Models to try, in order, after the primary fails
     *
     * @return array{ok: true, text: string, model: string}|array{ok: false, error: string, status: int}
     */
    public function run(
        string $model,
        MessageBag $messages,
        array $options = [],
        array $fallbackModels = [],
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
    ): array;
}
