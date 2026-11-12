<?php

namespace App\Actions\Fit;

use App\Actions\Jobs\CountryEligibility;
use App\Actions\Money\ExchangeRates;
use App\Models\JobMatch;
use App\Models\JobPosting;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;

/**
 * Score one posting for one user in PHP and store the score with the inputs and the rules that made it (ADR 0036).
 */
final class ScoreJob
{
    public function __construct(
        private FitScorer $scorer,
        private ExplainFit $explainFit,
        private ExchangeRates $exchangeRates,
        private CountryEligibility $countryEligibility,
    ) {}

    /**
     * Gather the inputs, score them, and upsert the user's match for the posting.
     *
     * The reasons come from ExplainFit and never change the score. When they are unavailable, the score is stored with no reasons.
     */
    public function handle(User $user, JobPosting $posting, bool $withReasons = true): JobMatch
    {
        $user->load(['profile', 'skills', 'primaryResume']);
        $posting->load('skills');
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

        $input = new FitInput(
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
        $score = $this->scorer->fromConfig($input);
        $reasons = [];

        if ($withReasons) {
            $explanation = $this->explainFit->handle($user, $posting);

            if ($explanation->status === 'ready') {
                $reasons = array_column(array_slice($explanation->reasons, 0, 5), 'claim');
            }
        }

        return $user->jobMatches()->updateOrCreate(['job_posting_id' => $posting->id], [
            'score' => $score->score,
            'breakdown' => $score->breakdown,
            'inputs' => $input->toArray(),
            'matched_skills' => $score->matchedSkills,
            'missing_skills' => $score->missingSkills,
            'scoring_version' => $score->version,
            'profile_version' => $profile?->version,
            'resume_id' => $resume?->id,
            'reasons' => $reasons,
            'scored_at' => now(),
        ]);
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
