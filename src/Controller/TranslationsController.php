<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TranslationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Translates text from one language to another, optionally in a requested tone.
 *
 * POST /v1/translations
 * {
 *   "from": "English",        required, never auto-detected
 *   "to": "French",           required
 *   "text": "Hello there",    required
 *   "tone": "formal"          optional: formal | informal | simple | kitten | none
 * }
 */
final class TranslationsController extends AbstractController
{
    private const MAX_LANGUAGE_CHARS = 50;
    private const MAX_TEXT_CHARS = 20000;

    public function __construct(
        private readonly TranslationService $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/v1/translations', name: 'translations', methods: ['POST'])]
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

        // {} decodes to [], so only a non-empty list is a wrong shape (e.g. [1,2]).
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            return $this->errorResponse('Malformed JSON body.', 'invalid_request_error', 'invalid_json', 400);
        }

        $from = $data['from'] ?? null;
        if (!self::isValidLanguage($from)) {
            return $this->errorResponse(
                'Field "from" is required and must name the source language.',
                'invalid_request_error',
                'invalid_from',
                400,
            );
        }

        $to = $data['to'] ?? null;
        if (!self::isValidLanguage($to)) {
            return $this->errorResponse(
                'Field "to" is required and must name the target language.',
                'invalid_request_error',
                'invalid_to',
                400,
            );
        }

        $text = $data['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            return $this->errorResponse(
                'Field "text" is required and must contain the text to translate.',
                'invalid_request_error',
                'invalid_text',
                400,
            );
        }
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            return $this->errorResponse(
                'Field "text" is too long (maximum ' . self::MAX_TEXT_CHARS . ' characters).',
                'invalid_request_error',
                'text_too_long',
                400,
            );
        }

        $tone = TranslationService::normaliseTone(self::optionalTone($data));
        if ($tone === null) {
            return $this->errorResponse(
                'Field "tone" must be one of: ' . implode(', ', TranslationService::TONES) . ' (or omitted).',
                'invalid_request_error',
                'invalid_tone',
                400,
            );
        }

        $from = trim($from);
        $to = trim($to);

        $result = $this->translator->translate($from, $to, $text, $tone);

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $this->logger->info('translations', [
            'status' => $result['ok'] ? 200 : $result['status'],
            'latency_ms' => $latencyMs,
            'tone' => $tone,
        ]);

        if (!$result['ok']) {
            return $this->errorResponse($result['error'], 'api_error', 'translation_error', $result['status']);
        }

        if ($result['translation'] === '') {
            return $this->errorResponse('Model returned an empty translation.', 'api_error', 'empty_translation', 502);
        }

        return new JsonResponse([
            'translation' => $result['translation'],
            'from' => $from,
            'to' => $to,
            'tone' => $tone,
            'model' => $result['model'],
        ]);
    }

    /**
     * Returns the raw tone value, or null when the field is absent/null.
     *
     * Non-string values become a sentinel that normaliseTone() rejects, so a
     * `"tone": ["formal"]` payload is a 400 rather than a type error.
     */
    private static function optionalTone(array $data): ?string
    {
        if (!array_key_exists('tone', $data) || $data['tone'] === null) {
            return null;
        }

        return is_string($data['tone']) ? $data['tone'] : "\0invalid";
    }

    private static function isValidLanguage(mixed $language): bool
    {
        if (!is_string($language)) {
            return false;
        }

        $language = trim($language);
        if ($language === '' || mb_strlen($language) > self::MAX_LANGUAGE_CHARS) {
            return false;
        }

        // Must contain at least one letter: rejects "12345" and punctuation-only values.
        return preg_match('/\p{L}/u', $language) === 1;
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
