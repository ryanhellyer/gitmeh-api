<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Translates text with the model configured under %translations.model%.
 *
 * Tone is optional and never combinable: exactly one of the four voices, or none
 * for a straight translation with no register instruction at all.
 */
final class TranslationService
{
    public const TONE_NONE = 'none';

    public const TONES = ['formal', 'informal', 'simple', 'kitten'];

    private const TEMPERATURE = 0.3;
    private const KITTEN_TEMPERATURE = 0.7;
    private const MAX_OUTPUT_TOKENS = 4096;

    private const TONE_INSTRUCTIONS = [
        'formal' => 'Formal register: polite pronouns and verb forms (e.g. "vous", "Sie", "usted"); no contractions, slang or colloquialisms.',
        'informal' => 'Informal, conversational register (e.g. "tu", "du", "tú"); contractions and everyday wording are fine.',
        'simple' => 'Plain language for readers with limited proficiency in the target language: short sentences, common vocabulary, no idioms or metaphor, grammar still correct.',
        'kitten' => 'The speaker is a small kitten: playful, cute, third-person "little paws" phrasing and the occasional "meow"/"purr", without changing, dropping or inventing any meaning. The result must stay in the target language.',
    ];

    public function __construct(
        private readonly ModelRunnerInterface $runner,
        #[Autowire('%translations.model%')]
        private readonly string $model,
    ) {
    }

    /**
     * Canonicalise a client-supplied tone.
     *
     * Absent/null/empty and the literal "none" all mean "no tone". Returns null
     * only for values that are not a supported tone, so callers can reject them.
     */
    public static function normaliseTone(?string $tone): ?string
    {
        if ($tone === null) {
            return self::TONE_NONE;
        }

        $tone = strtolower(trim($tone));

        if ($tone === '' || $tone === self::TONE_NONE) {
            return self::TONE_NONE;
        }

        return in_array($tone, self::TONES, true) ? $tone : null;
    }

    /**
     * Build the system instruction. The tone line is omitted entirely for
     * TONE_NONE — no dangling "Tone:" fragment for the model to interpret.
     */
    public static function prompt(string $from, string $to, string $tone): string
    {
        $lines = [
            "You are a translation engine. Translate the user's text from {$from} to {$to}.",
            'Preserve meaning, names, numbers, formatting and line breaks.',
        ];

        if ($tone !== self::TONE_NONE) {
            $lines[] = 'Tone: ' . self::TONE_INSTRUCTIONS[$tone];
        }

        $lines[] = 'Reply with ONLY the translation - no preamble, notes, quotes or explanation.';

        return implode("\n", $lines);
    }

    public static function temperatureFor(string $tone): float
    {
        return $tone === 'kitten' ? self::KITTEN_TEMPERATURE : self::TEMPERATURE;
    }

    /**
     * @return array{ok: true, translation: string, model: string}|array{ok: false, error: string, status: int}
     */
    public function translate(string $from, string $to, string $text, string $tone = self::TONE_NONE): array
    {
        $messages = new MessageBag(
            Message::forSystem(self::prompt($from, $to, $tone)),
            Message::ofUser($text),
        );

        $result = $this->runner->run(
            $this->model,
            $messages,
            [
                'temperature' => self::temperatureFor($tone),
                'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            ],
        );

        if (!$result['ok']) {
            return $result;
        }

        // Unlike commit messages, translations are multi-line: keep the whole reply.
        return [
            'ok' => true,
            'translation' => trim($result['text']),
            'model' => $result['model'],
        ];
    }
}
