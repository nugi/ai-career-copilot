<?php

namespace App\Actions\Profile;

use Collator;
use ResourceBundle;

final class CountryList
{
    /**
     * Codes in the ICU region data that are not countries (regions, groupings and the unknown region).
     */
    private const array NOT_COUNTRIES = ['EU', 'EZ', 'UN', 'QO', 'XA', 'XB', 'ZZ'];

    /**
     * The English country names, keyed by ISO 3166-1 alpha-2 code and sorted by name.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        $names = [];

        foreach (ResourceBundle::create('en', 'ICUDATA-region')['Countries'] as $code => $name) {
            if (preg_match('/^[A-Z]{2}$/', (string) $code) === 1 && ! in_array($code, self::NOT_COUNTRIES, true)) {
                $names[(string) $code] = (string) $name;
            }
        }

        (new Collator('en'))->asort($names);

        return $names;
    }

    /**
     * Determine whether the code is a country in the list.
     */
    public function isValid(string $code): bool
    {
        return array_key_exists($code, $this->names());
    }
}
