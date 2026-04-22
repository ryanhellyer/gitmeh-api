<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\GitmehCommitMessageGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class GitmehChatCompletionsController extends Controller
{
    private const UNIFIED_DIFF_PREFIX = "Unified diff:\n";

    private const UNIFIED_DIFF_PREFIX_CR = "Unified diff:\r\n";

    public function __construct(
        private GitmehCommitMessageGenerator $generator
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $started = microtime(true);
        $maxBytes = (int) config('gitmeh.max_json_request_bytes', 2_097_152);

        $raw = $request->getContent();
        if (strlen($raw) > $maxBytes) {
            return $this->errorResponse(
                'Request body exceeds maximum allowed size.',
                'invalid_request_error',
                'request_too_large',
                413
            );
        }

        if ($raw === '') {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        try {
            /** @var mixed $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        if (! is_array($data)) {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        if (array_key_exists('model', $data) && ! is_string($data['model'])) {
            return $this->errorResponse('Field "model" must be a string.', 'invalid_request_error', 'invalid_model', 400);
        }

        if (! isset($data['messages']) || ! is_array($data['messages'])) {
            return $this->errorResponse('Field "messages" is required and must be an array.', 'invalid_request_error', 'missing_messages', 400);
        }

        $systemParts = [];
        $lastUserContent = null;

        foreach ($data['messages'] as $index => $message) {
            if (! is_array($message)) {
                return $this->errorResponse("messages[{$index}] must be an object.", 'invalid_request_error', 'invalid_messages', 400);
            }
            $role = $message['role'] ?? null;
            if (! is_string($role)) {
                return $this->errorResponse("messages[{$index}].role is required.", 'invalid_request_error', 'invalid_messages', 400);
            }
            $content = $message['content'] ?? null;
            if ($role === 'system') {
                if (! is_string($content)) {
                    return $this->errorResponse("messages[{$index}].content must be a string.", 'invalid_request_error', 'invalid_messages', 400);
                }
                if ($content !== '') {
                    $systemParts[] = $content;
                }
            }
            if ($role === 'user') {
                if (! is_string($content)) {
                    return $this->errorResponse("messages[{$index}].content must be a string.", 'invalid_request_error', 'invalid_messages', 400);
                }
                $lastUserContent = $content;
            }
        }

        if ($lastUserContent === null) {
            return $this->errorResponse('At least one user message is required.', 'invalid_request_error', 'missing_user_message', 400);
        }

        $diff = $this->stripUnifiedDiffPrefix($lastUserContent);
        if (trim($diff) === '') {
            return $this->errorResponse('User message content is empty after extracting the diff.', 'invalid_request_error', 'empty_diff', 400);
        }

        $instruction = $systemParts !== []
            ? implode("\n\n", $systemParts)
            : $this->generator->defaultInstruction();

        $result = $this->generator->generate($instruction, $diff);

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        Log::info('gitmeh.chat_completions', [
            'status' => $result['ok'] ? 200 : $result['status'],
            'latency_ms' => $latencyMs,
        ]);

        if (! $result['ok']) {
            return $this->errorResponse($result['error'], 'api_error', 'inference_error', $result['status']);
        }

        $message = $result['message'];
        if ($message === '') {
            return $this->errorResponse('Model returned an empty commit message.', 'api_error', 'empty_content', 502);
        }

        return response()->json([
            'id' => 'chatcmpl-gitmeh-'.bin2hex(random_bytes(8)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => is_string($data['model'] ?? null) ? $data['model'] : 'gitmeh-hosted',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $message,
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ], 200, ['Content-Type' => 'application/json']);
    }

    private function stripUnifiedDiffPrefix(string $content): string
    {
        if (str_starts_with($content, self::UNIFIED_DIFF_PREFIX)) {
            return substr($content, strlen(self::UNIFIED_DIFF_PREFIX));
        }
        if (str_starts_with($content, self::UNIFIED_DIFF_PREFIX_CR)) {
            return substr($content, strlen(self::UNIFIED_DIFF_PREFIX_CR));
        }

        return $content;
    }

    private function errorResponse(string $message, string $type, string $code, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'message' => $message,
                'type' => $type,
                'code' => $code,
            ],
        ], $status, ['Content-Type' => 'application/json']);
    }
}
