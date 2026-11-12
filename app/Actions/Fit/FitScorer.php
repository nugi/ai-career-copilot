<?php

namespace App\Actions\Fit;

/**
 * @phpstan-import-type Factor from FitScore
 *
 * @phpstan-type Rules array{seniority_years: array<string, int>, salary_zero_below: float, eligibility: array<string, float>}
 */
final class FitScorer
{
    /**
     * Read configuration only at the boundary; score() is a pure calculation.
     */
    public function fromConfig(FitInput $input): FitScore
    {
        return $this->score($input, config('fit-scoring.weights'), [
            'seniority_years' => config('fit-scoring.seniority_years'),
            'salary_zero_below' => config('fit-scoring.salary_zero_below'),
            'eligibility' => config('fit-scoring.eligibility'),
        ], config('fit-scoring.version'));
    }

    /**
     * @param  array<string, int>  $weights
     * @param  Rules  $rules
     */
    public function score(FitInput $input, array $weights, array $rules, string $version): FitScore
    {
        $userSkills = array_map(fn (string $name): string => mb_strtolower(trim($name)), $input->userSkills);
        $matches = fn (string $name): bool => in_array(mb_strtolower(trim($name)), $userSkills, true);
        $requiredMatches = array_values(array_filter($input->requiredSkills, $matches));
        $niceMatches = array_values(array_filter($input->niceSkills, $matches));
        $missing = array_values(array_filter($input->requiredSkills, fn (string $name): bool => ! $matches($name)));
        $needed = $input->yearsRequired ?? $rules['seniority_years'][$input->seniority ?? ''] ?? null;
        $seniority = $input->yearsOfExperience === null || $needed === null
            ? null
            : ($needed === 0 ? 1.0 : min(1.0, max(0.0, $input->yearsOfExperience / $needed)));
        $salary = null;

        if ($input->salaryFloor !== null && $input->salaryMax !== null) {
            $zero = $rules['salary_zero_below'] * $input->salaryFloor;
            $salary = match (true) {
                $input->salaryMax >= $input->salaryFloor => 1.0,
                $input->salaryMax < $zero => 0.0,
                default => ($input->salaryMax - $zero) / ($input->salaryFloor - $zero),
            };
        }

        $breakdown = [
            'required_skills' => $this->factor($input->requiredSkills === [] ? null : count($requiredMatches) / count($input->requiredSkills), $weights['required_skills'], ['matched' => count($requiredMatches), 'of' => count($input->requiredSkills)]),
            'nice_skills' => $this->factor($input->niceSkills === [] ? null : count($niceMatches) / count($input->niceSkills), $weights['nice_skills'], ['matched' => count($niceMatches), 'of' => count($input->niceSkills)]),
            'seniority' => $this->factor($seniority, $weights['seniority'], ['years' => $input->yearsOfExperience, 'needed' => $needed, 'from' => $input->yearsRequired !== null ? 'years_required' : 'seniority']),
            'salary' => $this->factor($salary, $weights['salary'], ['floor' => $input->salaryFloor, 'max' => $input->salaryMax]),
            'location' => $this->factor($input->arrangementMatches ? $rules['eligibility'][$input->eligibility] : 0.0, $weights['location'], ['arrangement_matches' => $input->arrangementMatches, 'eligibility' => $input->eligibility]),
        ];
        $weighted = 0.0;
        $knownWeight = 0;

        foreach ($breakdown as $factor) {
            if ($factor['value'] !== null) {
                $weighted += $factor['weight'] * $factor['value'];
                $knownWeight += $factor['weight'];
            }
        }

        $score = $knownWeight === 0 ? 0 : (int) round(100 * $weighted / $knownWeight);

        return new FitScore(max(0, min(100, $score)), $breakdown, [...$requiredMatches, ...$niceMatches], $missing, $version);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return Factor
     */
    private function factor(?float $value, int $weight, array $detail): array
    {
        return ['value' => $value === null ? null : round($value, 3), 'weight' => $weight, 'detail' => $detail];
    }
}
