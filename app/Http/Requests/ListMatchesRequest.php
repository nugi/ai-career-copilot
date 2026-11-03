<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListMatchesRequest extends FormRequest
{
    /**
     * Get the validation rules for the country filter.
     *
     * @return array{country: list<string>}
     */
    public function rules(): array
    {
        return [
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
        ];
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
