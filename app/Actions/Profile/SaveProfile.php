<?php

namespace App\Actions\Profile;

use App\Enums\SkillSource;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveProfile
{
    /**
     * Save the user's job preferences and skills, and mark onboarding as complete.
     *
     * The profile version increases when anything that affects matching changes,
     * so data derived from the profile (such as embeddings) can detect it is stale.
     * Saves for the same user run one at a time (the user row is locked, because
     * a first save has no profile row to lock), so two saves never share a version.
     * The profile form lists every skill of the user, so each save turns every listed skill into a profile skill.
     *
     * @param  array{headline: string|null, target_roles: list<string>, preferred_locations: list<string>, work_arrangements: list<string>, min_salary: int|null, salary_currency: string|null, salary_period: string|null}  $preferences
     * @param  list<array{name: string, proficiency: string}>  $skills
     */
    public function handle(User $user, array $preferences, array $skills): Profile
    {
        return DB::transaction(function () use ($user, $preferences, $skills): Profile {
            User::query()->whereKey($user->getKey())->lockForUpdate()->sole();

            $profile = $user->profile()->first() ?? $user->profile()->make();
            $profile->fill($preferences);

            $changes = $user->skills()->sync($this->resolveSkills($skills));
            $skillsChanged = $changes['attached'] !== [] || $changes['detached'] !== [] || $changes['updated'] !== [];

            if ($profile->exists && ($profile->isDirty() || $skillsChanged)) {
                $profile->version++;
            }

            $profile->completed_at ??= now();
            $profile->save();
            $user->setRelation('profile', $profile);

            return $profile;
        });
    }

    /**
     * Map each typed skill to its canonical skill, with the profile source. When two entries resolve to the same skill, the last one wins.
     *
     * @param  list<array{name: string, proficiency: string}>  $skills
     * @return array<int, array{proficiency: string, source: SkillSource}>
     */
    private function resolveSkills(array $skills): array
    {
        $resolved = [];

        foreach ($skills as $skill) {
            $resolved[Skill::findOrCreateByName($skill['name'])->id] = [
                'proficiency' => $skill['proficiency'],
                'source' => SkillSource::Profile,
            ];
        }

        return $resolved;
    }
}
