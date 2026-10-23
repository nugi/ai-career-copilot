<?php

namespace App\JobSources\Normalization;

use App\Enums\JobRegion;

/**
 * The countries, region and city that one location text names.
 */
final readonly class ParsedLocation
{
    /**
     * Hold only the city, countries and region that the source unambiguously identifies.
     *
     * @param  list<string>  $countryCodes
     */
    public function __construct(
        public ?string $city,
        public array $countryCodes,
        public ?JobRegion $region,
    ) {}

    /**
     * Prefer country restrictions to a broader region in duplicate detection.
     */
    public function key(): string
    {
        return $this->countryCodes !== [] ? implode('-', $this->countryCodes) : ($this->region->value ?? 'unknown');
    }
}
