<?php

namespace App\JobSources\Normalization;

use Locale;
use RuntimeException;
use Transliterator;

/**
 * Resolves a country name in any of the app's common locales, or an alias such as "USA", to its ISO 3166-1
 * alpha-2 code. The names come from ext-intl, so no country list is kept in the code.
 */
final class CountryResolver
{
    /** @var array<string, string>|null */
    private static ?array $names = null;

    private static ?Transliterator $transliterator = null;

    /**
     * Resolve localized country names, country codes and common aliases.
     */
    public function code(string $name): ?string
    {
        self::$names ??= self::names();

        return self::$names[self::key($name)] ?? null;
    }

    /**
     * Build the localized country map once, excluding non-country ICU regions.
     *
     * @return array<string, string>
     */
    private static function names(): array
    {
        $names = [];

        foreach (range('A', 'Z') as $first) {
            foreach (range('A', 'Z') as $second) {
                $code = $first.$second;
                $english = Locale::getDisplayRegion('-'.$code, 'en');

                if ($english === false || $english === $code || in_array($code, ['EU', 'EZ', 'UN', 'QO', 'XA', 'XB', 'ZZ'], true)) {
                    continue;
                }

                $names[self::key($code)] = $code;

                foreach (['en', 'id', 'es', 'de', 'fr', 'pt'] as $locale) {
                    $name = Locale::getDisplayRegion('-'.$code, $locale);

                    if ($name !== false) {
                        $names[self::key($name)] = $code;
                    }
                }
            }
        }

        foreach ([
            'US' => ['usa', 'us', 'u s a', 'united states of america', 'america'],
            'GB' => ['uk', 'u k', 'great britain', 'britain', 'england', 'scotland', 'wales', 'northern ireland'],
            'AE' => ['uae'],
            'TR' => ['turkey', 'turkiye'],
            'KR' => ['south korea', 'korea'],
            'RU' => ['russia'],
            'VN' => ['vietnam'],
            'CZ' => ['czech republic', 'czechia'],
            'NL' => ['holland'],
        ] as $code => $aliases) {
            foreach ($aliases as $alias) {
                $names[self::key($alias)] = $code;
            }
        }

        return $names;
    }

    /**
     * Compare country names without accents, case, dots or punctuation.
     */
    private static function key(string $name): string
    {
        self::$transliterator ??= Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
        $key = self::$transliterator?->transliterate($name);

        if (! is_string($key)) {
            throw new RuntimeException('Unable to transliterate a country name.');
        }

        $key = str_replace('.', '', $key);

        return trim(preg_replace('/[^\p{L}]+/u', ' ', $key) ?? $key);
    }
}
