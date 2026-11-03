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
    public function text(Profile $profile): string
    {
        $profile->loadMissing('user.skills');
        $skills = $profile->user->skills
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
            ->take(10)->pluck('name')->sort()->values()->all();

        return implode(', ', $profile->target_roles).($skills === [] ? '' : "\nSkills: ".implode(', ', $skills));
    }

    /**
     * Identify the exact text used to generate the cached query vector.
     */
    public function hash(string $text): string
    {
        return hash('sha256', $text);
    }
}
