<?php

namespace App\JobSources\Normalization;

use App\Enums\JobRegion;

/**
 * Reads the countries, region and city that a source's location text names. It never guesses a city or a
 * country from text it does not know.
 */
final class LocationParser
{
    /**
     * Use the shared localized country resolver.
     */
    public function __construct(private CountryResolver $countries = new CountryResolver) {}

    /**
     * Read country and region restrictions without guessing cities from unknown text.
     */
    public function parse(?string $location): ParsedLocation
    {
        if ($location === null || trim($location) === '') {
            return new ParsedLocation(null, [], JobRegion::Worldwide);
        }

        $parts = preg_split('~[,;|]|\s+/\s+|\s+or\s+~iu', $location) ?: [];
        $countryCodes = [];
        $region = null;
        $cleaned = [];
        $partCountries = [];
        $partRegions = [];

        foreach ($parts as $part) {
            $original = trim($part);
            $part = preg_replace('/[()\[\]{}]/u', ' ', $original) ?? $original;
            $remoteOnly = strtolower(trim($part)) === 'remote';
            $part = preg_replace('/\b(?:fully\s+remote|remote|based)\b/iu', '', $part) ?? $part;
            $part = trim(preg_replace('/\s+/u', ' ', $part) ?? $part);
            $partRegion = $this->region($remoteOnly ? 'remote' : $part);
            $country = $partRegion === null ? $this->countries->code($part) : null;
            $cleaned[] = $part;
            $partCountries[] = $country;
            $partRegions[] = $partRegion;
            $region ??= $partRegion;

            if ($country !== null) {
                $countryCodes[] = $country;
            }
        }

        $city = count($parts) === 2
            && $partCountries[1] !== null
            && $partCountries[0] === null
            && $partRegions[0] === null
            && $cleaned[0] !== ''
                ? $cleaned[0]
                : null;
        $countryCodes = array_values(array_unique($countryCodes));
        sort($countryCodes);

        return new ParsedLocation($city, $countryCodes, $region);
    }

    /**
     * Map the source's region aliases, before treating a part as a country code.
     */
    private function region(string $part): ?JobRegion
    {
        return match (strtolower($part)) {
            'anywhere', 'worldwide', 'global', 'remote' => JobRegion::Worldwide,
            'americas' => JobRegion::Americas,
            'north america', 'na' => JobRegion::NorthAmerica,
            'latam', 'latin america', 'south america' => JobRegion::Latam,
            'europe', 'eu', 'eea' => JobRegion::Europe,
            'emea' => JobRegion::Emea,
            'middle east', 'mena' => JobRegion::MiddleEast,
            'africa' => JobRegion::Africa,
            'apac', 'asia', 'asia pacific', 'oceania' => JobRegion::Apac,
            default => null,
        };
    }
}
