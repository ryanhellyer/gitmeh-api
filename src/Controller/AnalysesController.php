<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\JsonRequest;
use App\Service\AnalysisService;
use App\Service\SupportedLanguages;
use App\Service\TextOffsets;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Checks a learner's own text and returns the problems as spans.
 *
 * POST /v1/analyses
 * {
 *   "language": "German",                 required, English language name
 *   "explanation_language": "English",    required, English language name
 *   "text": "...",                        required, 1-5,000 UTF-16 units
 *   "checks": ["grammar", ...]            optional, defaults to all four
 * }
 */
final class AnalysesController extends AbstractController
{
    private const MAX_TEXT_UNITS = 5000;

    public function __construct(
        private readonly AnalysisService $analyser,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/analyses', name: 'analyses', methods: ['POST'])]
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

        $explanationLanguage = SupportedLanguages::canonical($data['explanation_language'] ?? null);
        if ($explanationLanguage === null) {
            return JsonRequest::error(
                'Field "explanation_language" is required and must be a supported language name.',
                'invalid_request_error',
                'invalid_explanation_language',
                400,
            );
        }

        $text = $data['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return JsonRequest::error(
                'Field "text" is required and must contain the text to check.',
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

        $checks = self::checks($data);
        if ($checks === null) {
            return JsonRequest::error(
                'Field "checks" must be a non-empty subset of: ' . implode(', ', AnalysisService::CHECKS) . ' (or omitted).',
                'invalid_request_error',
                'invalid_checks',
                400,
            );
        }

        $result = $this->analyser->analyse($language, $explanationLanguage, $text, $checks);

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $this->logger->info('analyses', [
            'status' => $result['ok'] ? 200 : $result['status'],
            'latency_ms' => $latencyMs,
            'language' => $language,
            'explanation_language' => $explanationLanguage,
            'checks' => $checks,
            'issue_count' => $result['ok'] ? count($result['issues']) : null,
        ]);

        if (!$result['ok']) {
            return JsonRequest::error($result['error'], 'api_error', 'analysis_error', $result['status']);
        }

        $payload = ['issues' => $result['issues']];
        if ($result['corrected_text'] !== null) {
            $payload['corrected_text'] = $result['corrected_text'];
        }

        return new JsonResponse($payload);
    }

    /**
     * Normalise the optional checks list. Null means all four; an explicitly
     * empty list is treated the same way as an omitted one.
     *
     * @param array<string, mixed> $data
     *
     * @return list<string>|null
     */
    private static function checks(array $data): ?array
    {
        if (!array_key_exists('checks', $data) || $data['checks'] === null) {
            return AnalysisService::CHECKS;
        }

        if (!is_array($data['checks']) || !array_is_list($data['checks'])) {
            return null;
        }

        $checks = [];
        foreach ($data['checks'] as $check) {
            if (!is_string($check)) {
                return null;
            }

            $check = strtolower(trim($check));
            if (!in_array($check, AnalysisService::CHECKS, true)) {
                return null;
            }

            if (!in_array($check, $checks, true)) {
                $checks[] = $check;
            }
        }

        return $checks === [] ? AnalysisService::CHECKS : $checks;
    }
}
