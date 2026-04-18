<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Throwable;

use function Laravel\Ai\agent;

class GitmehController extends Controller
{
    private const DEFAULT_PROMPT = 'Write a short, professional git commit message for these changes. Use imperative mood. Only return the message text:';

    public function __invoke(Request $request)
    {
        $key = config('ai.providers.openrouter.key');
        if ($key === null || $key === '') {
            return $this->plain('Error: OPENROUTER_API_KEY is missing.', 500);
        }

        $smartDiff = $request->getContent();
        $instruction = config('services.openrouter.prompt');
        if (! is_string($instruction) || trim($instruction) === '') {
            $instruction = self::DEFAULT_PROMPT;
        }

        $model = config('ai.providers.openrouter.models.text.default', 'google/gemma-3-4b-it');
        $timeout = (int) config('services.openrouter.timeout', 120);

        try {
            $agentResponse = agent($instruction)->prompt(
                $smartDiff,
                [],
                'openrouter',
                $model,
                $timeout,
            );
        } catch (Throwable $e) {
            return $this->plain('OpenRouter error: '.$e->getMessage(), 502);
        }

        $content = trim($agentResponse->text);
        if ($content === '' || $content === 'null') {
            return $this->plain('The AI failed. Probably went on a coffee break.', 502);
        }

        $firstLine = explode("\n", $content, 2)[0];

        return $this->plain(trim($firstLine), 200);
    }

    private function plain(string $body, int $status)
    {
        return response($body, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
