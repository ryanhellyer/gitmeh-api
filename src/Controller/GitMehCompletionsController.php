<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\ApiEndpoints;
use App\Http\JsonRequest;
use App\Service\GitMehCompletionService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * gitmeh's commit-message endpoint.
 *
 * POST /v1/git_meh/completions
 * {
 *   "diff": "Unified diff:\ndiff --git a/main.go b/main.go\n...",  required
 *   "model": "gitmeh-hosted",        optional, ignored (the server model is fixed)
 *   "instructions": "...",           optional, ignored (the server prompt is fixed)
 * }
 *
 * The response is OpenAI-shaped so existing gitmeh clients keep parsing it:
 * {"choices":[{"message":{"role":"assistant","content":"feat(api): ..."}}]}
 *
 * This is deliberately NOT a chat endpoint. The system message is always the
 * standard instructions from config/packages/gitmeh.yaml; caller-supplied
 * "instructions" are accepted for wire compatibility but never used, and the
 * user content must be a unified diff. The old OpenAI URL only redirects here.
 */
final class GitMehCompletionsController extends AbstractController
{
    private const UNIFIED_DIFF_PREFIXES = ["Unified diff:\n", "Unified diff:\r\n"];

    public function __construct(
        private readonly GitMehCompletionService $service,
        private readonly LoggerInterface $logger,
        #[Autowire('%gitmeh.hosted_bearer_token%')]
        private readonly string $publicToken,
    ) {
    }

    #[Route('/v1/git_meh/completions', name: 'git_meh_completions', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $started = microtime(true);

        if (!$this->hasValidToken($request)) {
            return $this->error('Invalid API key.', Response::HTTP_UNAUTHORIZED);
        }

        $data = JsonRequest::decode($request);
        if ($data === null) {
            return $this->error('Malformed JSON body.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $diff = $data['diff'] ?? null;

        // Compatibility bridge for gitmeh releases that still post the old
        // OpenAI-shaped body: the only accepted form is one optional system
        // message followed by one user message carrying the diff. The system
        // message is ignored, exactly like the new "instructions" field.
        if ($diff === null && array_key_exists('messages', $data)) {
            $diff = $this->diffFromLegacyMessages($data['messages']);

            if ($diff === null) {
                return $this->error(
                    'Legacy "messages" must contain one optional system message followed by one user message carrying the diff.',
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        if (!is_string($diff) || trim($diff) === '') {
            return $this->error('Field "diff" is required and must contain a unified diff.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (strlen($diff) > GitMehCompletionService::MAX_DIFF_BYTES) {
            return $this->error('Field "diff" is too large.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$this->looksLikeUnifiedDiff($diff)) {
            return $this->error('Field "diff" must contain a unified diff (git diff output, or ---/+++/@@ headers).', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (array_key_exists('instructions', $data)) {
            $instructions = $data['instructions'];
            if ($instructions !== null && !is_string($instructions)) {
                return $this->error('Field "instructions" must be a string.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (is_string($instructions) && strlen($instructions) > GitMehCompletionService::MAX_INSTRUCTIONS_BYTES) {
                return $this->error('Field "instructions" is too long.', Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (array_key_exists('model', $data) && $data['model'] !== null && !is_string($data['model'])) {
            return $this->error('Field "model" must be a string.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->service->complete($diff);

        $this->logger->info('gitmeh.git_meh_completions', [
            'status' => $result['ok'] ? 200 : $result['status'],
            'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            'ignored_instructions' => isset($data['instructions']),
            'ignored_client_model' => isset($data['model']),
            'legacy_messages' => !array_key_exists('diff', $data),
        ]);

        if (!$result['ok']) {
            return $this->error($result['error'], $result['status']);
        }

        return new JsonResponse([
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => $result['message'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * The old URL is kept as a permanent redirect so gitmeh releases that still
     * call /v1/chat/completions reach the single real endpoint. GET is included
     * so visiting the URL in a browser shows the redirect instead of a 405.
     */
    #[Route('/v1/chat/completions', name: 'chat_completions_redirect', methods: ['GET', 'POST'])]
    public function redirectFromLegacyUrl(): RedirectResponse
    {
        // 308 keeps the POST method and body when the client follows.
        return new RedirectResponse(ApiEndpoints::GIT_MEH_COMPLETIONS, 308);
    }

    private function hasValidToken(Request $request): bool
    {
        $header = trim($request->headers->get('Authorization', ''));

        if (preg_match('/\ABearer\s+(\S+)\z/i', $header, $matches) !== 1) {
            return false;
        }

        return hash_equals($this->publicToken, $matches[1]);
    }

    private function diffFromLegacyMessages(mixed $messages): ?string
    {
        if (!is_array($messages) || $messages === [] || !array_is_list($messages)) {
            return null;
        }

        $lastIndex = count($messages) - 1;
        $roles = [];

        foreach ($messages as $index => $message) {
            if (!is_array($message) || !is_string($message['role'] ?? null) || !is_string($message['content'] ?? null)) {
                return null;
            }

            $role = $message['role'];
            if ($role !== 'system' && $role !== 'user') {
                return null;
            }
            if ($role === 'system' && $index !== 0) {
                return null;
            }

            $roles[$index] = $role;
        }

        $userIndexes = array_keys($roles, 'user', true);
        if (count($userIndexes) !== 1 || $userIndexes[0] !== $lastIndex) {
            return null;
        }
        if (count(array_keys($roles, 'system', true)) > 1) {
            return null;
        }

        return $messages[$lastIndex]['content'];
    }

    /**
     * Accepts git's own "diff --git" output (with a hunk or a rename/mode/
     * binary marker) and hand-written unified diffs with ---/+++/@@ headers.
     */
    private function looksLikeUnifiedDiff(string $diff): bool
    {
        foreach (self::UNIFIED_DIFF_PREFIXES as $prefix) {
            if (str_starts_with($diff, $prefix)) {
                $diff = substr($diff, strlen($prefix));

                break;
            }
        }

        if (preg_match('/^diff --git /m', $diff) === 1) {
            return preg_match('/^@@ /m', $diff) === 1
                || preg_match('/^(?:rename from|rename to|old mode|new mode|deleted file mode|new file mode|Binary files) /m', $diff) === 1;
        }

        return preg_match('/^--- /m', $diff) === 1
            && preg_match('/^\+\+\+ /m', $diff) === 1
            && preg_match('/^@@ -\d+(?:,\d+)? \+\d+(?:,\d+)? @@/m', $diff) === 1;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['message' => $message]], $status);
    }
}
