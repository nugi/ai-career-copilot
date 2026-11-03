<?php

namespace App\Http\Requests;

use App\Actions\Jobs\JobSearch;
use App\Enums\WorkArrangement;
use App\Models\Skill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListJobsRequest extends FormRequest
{
    /**
     * Get the validation rules for the filters in the query string.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'arrangement' => ['nullable', 'array'],
            'arrangement.*' => [Rule::in(['remote', 'hybrid', 'onsite'])],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'skill' => ['nullable', 'integer', Rule::exists('skills', 'id')->where('is_verified', true)],
            'floor' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Build the search from the validated filters.
     */
    public function toSearch(): JobSearch
    {
        $data = $this->validated();

        return new JobSearch(
            array_values(array_map(fn (string $value): WorkArrangement => WorkArrangement::from($value), $data['arrangement'] ?? [])),
            $this->searchCountry($data),
            isset($data['skill']) ? Skill::query()->where('is_verified', true)->findOrFail($this->integer('skill')) : null,
            (bool) ($data['floor'] ?? false),
        );
    }

    /**
     * The country to filter by. A country in the query string wins, and an empty one means any country. Without the key, the profile country applies.
     *
     * @param  array<string, mixed>  $data  The validated filters.
     */
    private function searchCountry(array $data): ?string
    {
        if (! $this->has('country')) {
            return $this->user()?->profile?->country_code;
        }

        return isset($data['country']) ? strtoupper($data['country']) : null;
    }
}
