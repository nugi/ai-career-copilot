<?php

namespace App\Actions\Jobs;

use App\Models\JobPosting;
use Illuminate\Support\Collection;
use Locale;

final class ListCountries
{
    /**
     * List the countries that live postings name, Indonesia, and the selected country, by their English name.
     *
     * @return list<array{code: string, name: string}>
     */
    public function handle(?string $selected): array
    {
        $single = JobPosting::query()->live()->whereNotNull('country_code')->selectRaw('country_code as code')->toBase();
        $multiple = JobPosting::query()->live()->selectRaw('jsonb_array_elements_text(country_codes::jsonb) as code')->toBase();

        return array_values($single->union($multiple)->pluck('code')->push('ID')
            ->when($selected !== null, fn (Collection $codes) => $codes->push($selected))->unique()
            ->map(fn (string $code): array => ['code' => $code, 'name' => Locale::getDisplayRegion('-'.$code, 'en') ?: $code])
            ->sortBy('name')->all());
    }
}
