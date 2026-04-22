<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Ai\AnonymousAgent;
use Throwable;

use function Laravel\Ai\agent;

final class GitmehCommitMessageGenerator
{
    private const DEFAULT_PROMPT = 'Write a short, professional git commit message for these changes. Use imperative mood. Only return the message text:';

    /**
     * @param  ?int  $inferenceTimeoutSeconds  When null, uses `services.openrouter.timeout` from config.
     * @return array{ok: true, message: string}|array{ok: false, error: string, status: int}
     */
    public function generate(string $instruction, string $unifiedDiff, ?int $inferenceTimeoutSeconds = null): array
    {
        $key = config('ai.providers.openrouter.key');
        if ($key === null || $key === '') {
            return ['ok' => false, 'error' => 'Error: OPENROUTER_API_KEY is missing.', 'status' => 500];
        }

        $model = config('ai.providers.openrouter.models.text.default', 'google/gemma-3-4b-it');
        $timeout = $inferenceTimeoutSeconds ?? (int) config('services.openrouter.timeout', 120);

        $agent = agent($instruction);
        if (! $agent instanceof AnonymousAgent) {
            return ['ok' => false, 'error' => 'Internal error: unsupported agent implementation.', 'status' => 500];
        }

        try {
            $agentResponse = $agent->prompt(
                $unifiedDiff,
                [],
                'openrouter',
                $model,
                $timeout,
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'OpenRouter error: '.$e->getMessage(), 'status' => 502];
        }

        $content = trim($agentResponse->text);
        if ($content === '' || $content === 'null') {
            return ['ok' => false, 'error' => 'The AI failed. Probably went on a coffee break.', 'status' => 502];
        }

        $firstLine = explode("\n", $content, 2)[0];

        return ['ok' => true, 'message' => trim($firstLine)];
    }

    public function defaultInstruction(): string
    {
        $instruction = config('services.openrouter.prompt');
        if (! is_string($instruction) || trim($instruction) === '') {
            return self::DEFAULT_PROMPT;
        }

        return $instruction;
    }
}
