<?php

namespace App\Actions\Fit;

use App\Actions\Jobs\CountryEligibility;
use App\Actions\Money\ExchangeRates;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;

/**
 * Gather the inputs that FitScorer needs for one user and one posting, without storing anything (ADR 0036).
 */
final class BuildFitInput
{
    public function __construct(
        private ExchangeRates $exchangeRates,
        private CountryEligibility $countryEligibility,
    ) {}

    /**
     * Build the input from the user's profile, skills and primary resume and the posting's skills, salary and location.
     *
     * Relations that are already loaded are reused, so a caller that scores many postings loads the user only once.
     */
    public function handle(User $user, JobPosting $posting): FitInput
    {
        $user->loadMissing(['profile', 'skills', 'primaryResume']);
        $posting->loadMissing('skills');
        $profile = $user->profile;
        $resume = $user->primaryResume;
        $required = [];
        $nice = [];

        foreach ($posting->skills as $skill) {
            if ($skill->pivot->is_required) {
                $required[] = $skill->name;
            } else {
                $nice[] = $skill->name;
            }
        }

        $eligibility = 'unclear';

        if ($profile?->country_code !== null) {
            $query = JobPosting::query()->select('job_postings.*')->whereKey($posting->id);
            $this->countryEligibility->apply($query, $profile->country_code);
            $eligiblePosting = $query->first();
            $eligibility = $eligiblePosting === null ? 'excluded' : (string) $eligiblePosting->getAttribute('eligibility');
        }

        return new FitInput(
            $required,
            $nice,
            array_values($user->skills->map(fn (Skill $skill): string => $skill->name)->all()),
            $resume?->yearsOfExperience(),
            $posting->years_required,
            ($posting->extracted_seniority ?? $posting->seniority)?->value,
            $this->salaryFloor($profile, $posting->salary_currency),
            $posting->annual_salary_max ?? $posting->annual_salary_min,
            $profile === null || $profile->work_arrangements->isEmpty() || $profile->work_arrangements->contains($posting->work_arrangement),
            $eligibility,
        );
    }

    /**
     * Convert the profile floor to an annual amount in the posting currency, as the floor filter does, without its 5% margin.
     */
    private function salaryFloor(?Profile $profile, ?string $currency): ?int
    {
        if ($profile === null || $profile->min_salary === null || $profile->salary_currency === null || $profile->salary_period === null || $currency === null) {
            return null;
        }

        $source = $profile->salary_currency->value;
        $rate = $this->exchangeRates->latestFrom($source)[$currency] ?? null;

        if ($rate === null) {
            return null;
        }

        return (int) floor($profile->min_salary * $profile->salary_period->annualFactor($source === 'IDR' ? 'ID' : null) * $rate->value);
    }
}
