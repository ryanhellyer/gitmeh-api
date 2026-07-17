<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GitmehCommitMessageGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OpenAI-compatible chat completions: same URL shape as OpenAI
 * (POST /v1/chat/completions) so the gitmeh client can treat this host like any
 * other OpenAI-style provider (JSON body, choices[].message.content).
 */
final class GitmehChatCompletionsController extends AbstractController
{
    private const UNIFIED_DIFF_PREFIX = "Unified diff:\n";
    private const UNIFIED_DIFF_PREFIX_CR = "Unified diff:\r\n";

    public function __construct(
        private readonly GitmehCommitMessageGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/chat/completions', name: 'chat_completions', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $started = microtime(true);

        $raw = $request->getContent();

        if ($raw === '') {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        if (!is_array($data)) {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        // Validate optional fields. NOTE: array_all() is PHP 8.4+, but this app
        // targets PHP >=8.2, so use array_filter + count instead.
        if (array_key_exists('model', $data) && !is_string($data['model'])) {
            return $this->errorResponse('Field "model" must be a string.', 'invalid_request_error', 'invalid_model', 400);
        }

        if (array_key_exists('fallback_models', $data)) {
            $fallback = $data['fallback_models'] ?? null;
            $allStrings = is_array($fallback)
                && count(array_filter($fallback, 'is_string')) === count($fallback);
            if (!$allStrings) {
                return $this->errorResponse('Field "fallback_models" must be an array of strings.', 'invalid_request_error', 'invalid_fallback_models', 400);
            }
        }

        // Validate messages
        if (!isset($data['messages']) || !is_array($data['messages'])) {
            return $this->errorResponse('Field "messages" is required and must be an array.', 'invalid_request_error', 'missing_messages', 400);
        }

        $systemParts = [];
        $lastUserContent = null;

        foreach ($data['messages'] as $index => $message) {
            if (!is_array($message)) {
                return $this->errorResponse("messages[{$index}] must be an object.", 'invalid_request_error', 'invalid_messages', 400);
            }
            $role = $message['role'] ?? null;
            if (!is_string($role)) {
                return $this->errorResponse("messages[{$index}].role is required.", 'invalid_request_error', 'invalid_messages', 400);
            }
            $content = $message['content'] ?? null;
            if ($role === 'system') {
                if (!is_string($content)) {
                    return $this->errorResponse("messages[{$index}].content must be a string.", 'invalid_request_error', 'invalid_messages', 400);
                }
                if ($content !== '') {
                    $systemParts[] = $content;
                }
            }
            if ($role === 'user') {
                if (!is_string($content)) {
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

        // A client-supplied API key (set by OptionalBearerSubscriber) is currently
        // ignored — the server's configured OpenRouter key is used. Supporting
        // per-request client keys requires a PlatformFactory (see PLAN.md Phase 6).
        $model = is_string($data['model'] ?? null) ? $data['model'] : null;
        $fallbackModels = array_filter(
            (array) ($data['fallback_models'] ?? []),
            'is_string'
        );

        $result = $this->generator->generate(
            instruction: $instruction,
            unifiedDiff: $diff,
            model: $model,
            fallbackModels: $fallbackModels,
        );

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $this->logger->info('gitmeh.chat_completions', [
            'status' => $result['ok'] ? 200 : $result['status'],
            'latency_ms' => $latencyMs,
        ]);

        if (!$result['ok']) {
            return $this->errorResponse($result['error'], 'api_error', 'inference_error', $result['status']);
        }

        if ($result['message'] === '') {
            return $this->errorResponse('Model returned an empty commit message.', 'api_error', 'empty_content', 502);
        }

        return new JsonResponse([
            'id' => 'chatcmpl-gitmeh-' . bin2hex(random_bytes(8)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $result['model'] ?? (is_string($data['model'] ?? null) ? $data['model'] : 'gitmeh-hosted'),
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $result['message'],
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
        ]);
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
        return new JsonResponse([
            'error' => [
                'message' => $message,
                'type' => $type,
                'code' => $code,
            ],
        ], $status);
    }
}
