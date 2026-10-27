<?php

namespace App\Http\Resources;

use App\Actions\Money\Rate;
use App\Enums\Currency;
use App\Enums\WorkArrangement;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Locale;

/**
 * One opening in the jobs list: its representative posting, with the pay columns of its pay posting (pay_*).
 *
 * @mixin JobPosting
 */
class JobListingResource extends JsonResource
{
    /**
     * Get the opening as an array, with the pay as posted and, when a rate exists, in the user's currency and period.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pay = $this->pay_salary_min === null && $this->pay_salary_max === null ? null : [
            'currency' => $this->pay_salary_currency,
            'min' => $this->pay_salary_min === null ? null : (int) $this->pay_salary_min,
            'max' => $this->pay_salary_max === null ? null : (int) $this->pay_salary_max,
            'period' => $this->pay_salary_period,
        ];

        return [
            'opening_id' => $this->opening_id,
            'title' => $this->title,
            'company' => $this->company_name,
            'location' => $this->displayLocation(),
            'work_arrangement' => ['value' => $this->work_arrangement->value, 'label' => $this->work_arrangement->label()],
            'posted_at' => $this->posted_at->toIso8601String(),
            'apply_url' => $this->apply_url,
            'pay' => $pay,
            'converted' => $this->converted($request),
            'skills' => $this->skills->sortBy([
                fn (Skill $first, Skill $second): int => (int) $second->pivot->is_required <=> (int) $first->pivot->is_required,
                ['name', 'asc'],
            ])->map(fn (Skill $skill): array => [
                'name' => $skill->name,
                'required' => (bool) $skill->pivot->is_required,
            ])->values()->all(),
        ];
    }

    /**
     * Get the place to show: the city and country, the countries, the remote region, or the posted location text.
     */
    private function displayLocation(): string
    {
        if ($this->city !== null && $this->city !== '') {
            return $this->city.($this->country_code !== null ? ', '.Locale::getDisplayRegion('-'.$this->country_code, 'en') : '');
        }

        if ($this->country_codes !== []) {
            return implode(', ', array_map(fn (string $code): string => Locale::getDisplayRegion('-'.$code, 'en'), $this->country_codes));
        }

        if ($this->work_arrangement === WorkArrangement::Remote) {
            return $this->region?->label() ?? 'Worldwide';
        }

        return $this->location ?? '';
    }

    /**
     * Convert the annual pay into the floor's currency and period. A monthly figure divides by the floor's own factor
     * (13 for IDR), so it reads on the same scale as the floor.
     *
     * @return array{currency: string, period: string, min: ?int, max: ?int, rate: float, rate_date: string, is_stale: bool}|null
     */
    private function converted(Request $request): ?array
    {
        /** @var Profile $profile */
        $profile = $request->attributes->get('jobs.profile');
        /** @var array<string, Rate> $rates */
        $rates = $request->attributes->get('jobs.rates', []);
        $rate = $rates[$this->pay_salary_currency] ?? null;

        if ($profile->salary_currency === null || $this->pay_salary_currency === null || $rate === null
            || ($this->pay_annual_salary_min === null && $this->pay_annual_salary_max === null)) {
            return null;
        }

        $factor = $profile->salary_period->annualFactor($profile->salary_currency === Currency::Idr ? 'ID' : null);
        $convert = fn (?int $annual): ?int => $annual === null ? null : (int) round($annual / $rate->value / $factor);

        return [
            'currency' => $profile->salary_currency->value,
            'period' => $profile->salary_period->value,
            'min' => $convert($this->pay_annual_salary_min === null ? null : (int) $this->pay_annual_salary_min),
            'max' => $convert($this->pay_annual_salary_max === null ? null : (int) $this->pay_annual_salary_max),
            'rate' => 1 / $rate->value,
            'rate_date' => $rate->date->toDateString(),
            'is_stale' => $rate->isStale,
        ];
    }
}
