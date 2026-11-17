<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The English language names the translator catalogue sends to the API.
 *
 * This mirrors src/translator/languages.ts in the web app. "Detect language"
 * is deliberately absent: the client resolves auto to English before sending.
 */
final class SupportedLanguages
{
    /** @var list<string> */
    public const NAMES = [
        'English',
        'Te reo Māori',
        'German',
        'French',
        'Spanish',
        'Italian',
        'Portuguese',
        'Dutch',
        'Swedish',
        'Danish',
        'Norwegian',
        'Finnish',
        'Polish',
        'Turkish',
        'Russian',
        'Japanese',
        'Korean',
        'Chinese',
        'Hindi',
        'Arabic',
        'Thai',
        'Vietnamese',
        'Indonesian',
        'Czech',
        'Greek',
        'Hebrew',
        'Hungarian',
        'Romanian',
        'Ukrainian',
        'Bengali',
        'Urdu',
        'Persian',
        'Swahili',
        'Filipino',
        'Malay',
        'Afrikaans',
        'Albanian',
        'Amharic',
        'Armenian',
        'Azerbaijani',
        'Basque',
        'Belarusian',
        'Bosnian',
        'Bulgarian',
        'Burmese',
        'Catalan',
        'Cebuano',
        'Chichewa',
        'Corsican',
        'Croatian',
        'Esperanto',
        'Estonian',
        'Fijian',
        'Galician',
        'Georgian',
        'Guarani',
        'Gujarati',
        'Haitian Creole',
        'Hausa',
        'Hawaiian',
        'Hmong',
        'Icelandic',
        'Igbo',
        'Irish',
        'Javanese',
        'Kannada',
        'Kazakh',
        'Khmer',
        'Kinyarwanda',
        'Kyrgyz',
        'Lao',
        'Latin',
        'Latvian',
        'Lithuanian',
        'Luxembourgish',
        'Macedonian',
        'Malagasy',
        'Malayalam',
        'Maltese',
        'Marathi',
        'Mongolian',
        'Nepali',
        'Odia',
        'Pashto',
        'Punjabi',
        'Quechua',
        'Samoan',
        'Scottish Gaelic',
        'Serbian',
        'Sesotho',
        'Shona',
        'Sindhi',
        'Sinhala',
        'Slovak',
        'Slovenian',
        'Somali',
        'Sundanese',
        'Tajik',
        'Tamil',
        'Telugu',
        'Tibetan',
        'Toki Pona',
        'Tongan',
        'Turkmen',
        'Uzbek',
        'Welsh',
        'Yiddish',
        'Yoruba',
        'Zulu',
        'Tahitian',
        'Nahuatl',
        'Klingon',
        'Shyriiwook',
        'Huttese',
        'Mando’a',
        'Elvish (Sindarin)',
        'Dothraki',
        'High Valyrian',
        'Pig Latin',
        'Pirate English',
    ];

    /** @var array<string, string>|null */
    private static ?array $lookup = null;

    /**
     * Resolve a client-supplied name to its canonical spelling, or null when
     * the name is not in the catalogue. Matching is case-insensitive and
     * ignores surrounding whitespace.
     */
    public static function canonical(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $name = trim($value);
        if ($name === '') {
            return null;
        }

        $lookup = self::lookup();

        return $lookup[mb_strtolower($name, 'UTF-8')] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function lookup(): array
    {
        if (self::$lookup === null) {
            self::$lookup = [];
            foreach (self::NAMES as $name) {
                self::$lookup[mb_strtolower($name, 'UTF-8')] = $name;
            }
        }

        return self::$lookup;
    }
}
