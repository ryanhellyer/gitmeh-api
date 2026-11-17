<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonRequest;
use App\Service\RewriteService;
use App\Service\SupportedLanguages;
use App\Service\TextOffsets;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rewrites a learner's text so it sounds more natural.
 *
 * POST /v1/rewrites
 * {
 *   "language": "German",       required, English language name
 *   "text": "...",              required, 1-5,000 UTF-16 units
 *   "style": "formal"           optional: formal | simple | playful (omitted = natural)
 * }
 */
final class RewritesController extends AbstractController
{
    private const MAX_TEXT_UNITS = 5000;

    public function __construct(
        private readonly RewriteService $rewriter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/rewrites', name: 'rewrites', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $started = microtime(true);

        $data = JsonRequest::decode($request);
        if ($data === null) {
            return JsonRequest::error('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        $language = SupportedLanguages::canonical($data['language'] ?? null);
        if ($language === null) {
            return JsonRequest::error(
                'Field "language" is required and must be a supported language name.',
                'invalid_request_error',
                'invalid_language',
                400,
            );
        }

        $text = $data['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return JsonRequest::error(
                'Field "text" is required and must contain the text to rewrite.',
                'invalid_request_error',
                'invalid_text',
                400,
            );
        }
        if (TextOffsets::length($text) > self::MAX_TEXT_UNITS) {
            return JsonRequest::error(
                'Field "text" is too long (maximum ' . self::MAX_TEXT_UNITS . ' characters).',
                'invalid_request_error',
                'text_too_long',
                400,
            );
        }

        $style = self::style($data);
        if ($style === false) {
            return JsonRequest::error(
                'Field "style" must be one of: ' . implode(', ', RewriteService::STYLES) . ' (or omitted).',
                'invalid_request_error',
                'invalid_style',
                400,
            );
        }

        $result = $this->rewriter->rewrite($language, $text, $style);

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $this->logger->info('rewrites', [
            'status' => $result['ok'] ? 200 : $result['status'],
            'latency_ms' => $latencyMs,
            'language' => $language,
            'style' => $style,
            'change_count' => $result['ok'] ? count($result['changes']) : null,
        ]);

        if (!$result['ok']) {
            return JsonRequest::error($result['error'], 'api_error', 'rewrite_error', $result['status']);
        }

        return new JsonResponse([
            'rewrite' => $result['rewrite'],
            'changes' => $result['changes'],
        ]);
    }

    /**
     * Null means "natural"; an explicit "natural" is accepted as a synonym so
     * a client can send its default either way. False means an invalid value.
     */
    private static function style(array $data): string|false|null
    {
        if (!array_key_exists('style', $data) || $data['style'] === null) {
            return null;
        }

        if (!is_string($data['style'])) {
            return false;
        }

        $style = strtolower(trim($data['style']));

        if ($style === '' || $style === 'natural') {
            return null;
        }

        return in_array($style, RewriteService::STYLES, true) ? $style : false;
    }
}
