<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GitmehController extends Controller
{
    private const DEFAULT_PROMPT = 'Write a short, professional git commit message for these changes. Use imperative mood. Only return the message text:';

    public function __invoke(Request $request)
    {
        $key = config('services.openrouter.key');
        if ($key === null || $key === '') {
            return $this->plain('Error: OPENROUTER_API_KEY is missing.', 500);
        }

        $smartDiff = $request->getContent();
        $instruction = config('services.openrouter.prompt');
        if (! is_string($instruction) || trim($instruction) === '') {
            $instruction = self::DEFAULT_PROMPT;
        }

        $prompt = $instruction.' '.$smartDiff;

        $model = config('services.openrouter.model', 'google/gemma-3-4b-it');

        $headers = [
            'Authorization' => 'Bearer '.$key,
            'Content-Type' => 'application/json',
        ];

        $referer = config('services.openrouter.http_referer');
        if (is_string($referer) && $referer !== '') {
            $headers['HTTP-Referer'] = $referer;
        }

        $title = config('services.openrouter.title');
        if (is_string($title) && $title !== '') {
            $headers['X-OpenRouter-Title'] = $title;
        }

        $response = Http::withHeaders($headers)
            ->timeout((int) config('services.openrouter.timeout', 120))
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        $json = $response->json();

        $apiErr = data_get($json, 'error.message') ?? data_get($json, 'error');
        if (! empty($apiErr)) {
            return $this->plain('OpenRouter error: '.$apiErr, 502);
        }

        if ($response->failed()) {
            return $this->plain('OpenRouter error: '.$response->body(), 502);
        }

        $content = data_get($json, 'choices.0.message.content');
        if ($content === null || $content === '' || $content === 'null') {
            return $this->plain('The AI failed. Probably went on a coffee break.', 502);
        }

        $content = trim((string) $content);
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
