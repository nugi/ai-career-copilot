<?php

namespace App\Actions\Jobs;

use App\Enums\SkillProficiency;
use App\Models\Profile;
use App\Models\Skill;

/**
 * Build a profile's query text in the same shape as a posting's embedding text.
 */
final class ProfileEmbeddingText
{
    /**
     * Choose the ten best skills, then sort their names for a stable vector input.
     */
    public function text(Profile $profile, ?string $role = null): string
    {
        $skills = $this->topSkills($profile);

        return (trim($role ?? '') !== '' ? trim($role) : implode(', ', $profile->target_roles)).($skills === [] ? '' : "\nSkills: ".implode(', ', $skills));
    }

    /**
     * Return non-empty target roles followed by the same skills used in the vector text.
     *
     * @return list<string>
     */
    public function keywordTerms(Profile $profile, ?string $role = null): array
    {
        $roles = trim($role ?? '') !== ''
            ? [trim($role)]
            : array_values(array_filter(array_map(trim(...), $profile->target_roles), fn (string $role): bool => $role !== ''));

        return [...$roles, ...$this->topSkills($profile)];
    }

    /**
     * Map each keyword term to its proficiency multiplier for the IdfProficiency weighting (ADR 0035): 1.0 for a target
     * role or an expert skill, down to 0.5 for a beginner one. Proficiency tilts the weight; it does not override rarity.
     *
     * @return array<string, float>
     */
    public function keywordMultipliers(Profile $profile): array
    {
        $profile->loadMissing('user.skills');
        $multipliers = [];

        foreach ($profile->target_roles as $role) {
            if (trim($role) !== '') {
                $multipliers[trim($role)] = 1.0;
            }
        }

        $skills = $this->topSkills($profile);

        foreach ($profile->user->skills as $skill) {
            if (in_array($skill->name, $skills, true)) {
                $multipliers[$skill->name] ??= match ($skill->pivot->proficiency) {
                    SkillProficiency::Expert => 1.0,
                    SkillProficiency::Advanced => 0.85,
                    SkillProficiency::Intermediate => 0.7,
                    SkillProficiency::Beginner => 0.5,
                    default => 0.7,
                };
            }
        }

        return $multipliers;
    }

    /**
     * @return list<string>
     */
    public function topSkills(Profile $profile): array
    {
        $profile->loadMissing('user.skills');

        return array_values($profile->user->skills
            ->sortBy(fn (Skill $skill): array => [
                match ($skill->pivot->proficiency) {
                    SkillProficiency::Expert => 0,
                    SkillProficiency::Advanced => 1,
                    SkillProficiency::Intermediate => 2,
                    SkillProficiency::Beginner => 3,
                    default => 4,
                },
                $skill->name,
            ])
            ->take(10)->map(fn (Skill $skill): string => $skill->name)->sort()->values()->all());
    }

    /**
     * Identify the exact text used to generate the cached query vector.
     */
    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
