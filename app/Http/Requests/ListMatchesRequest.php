<?php

namespace App\Http\Requests;

use App\Enums\SearchMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ListMatchesRequest extends FormRequest
{
    /**
     * Get the validation rules for the match filters.
     *
     * @return array{country: list<string>, mode: list<string|Enum>}
     */
    public function rules(): array
    {
        return [
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'mode' => ['nullable', Rule::enum(SearchMode::class)],
        ];
    }

    /**
     * Use the requested ranking mode, or the default mode when the query leaves it out or empty.
     */
    public function mode(): SearchMode
    {
        $mode = $this->validated('mode');

        return $mode === null || $mode === '' ? SearchMode::default() : SearchMode::from($mode);
    }

    /**
     * Use the profile country unless the query supplies a country, with an empty value meaning any country.
     */
    public function country(): ?string
    {
        $country = $this->has('country')
            ? $this->validated('country')
            : $this->user()?->profile?->country_code;

        return $country === null ? null : strtoupper($country);
    }
}
