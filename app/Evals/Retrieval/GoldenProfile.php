<?php

namespace App\Evals\Retrieval;

use App\Enums\SkillProficiency;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use App\Models\UserSkill;
use Illuminate\Database\Eloquent\Collection;

/**
 * Build an unsaved profile, with its user and skills, from a golden case (ADR 0035).
 *
 * Nothing is written: the ranking only reads the profile's filters and the user's skills. A skill name that the
 * catalogue knows takes its catalogue name, so the keyword terms match what a real user with that skill would have.
 */
final class GoldenProfile
{
    /**
     * @param  array{target_roles: list<string>, skills: array<string, string>, work_arrangements: list<string>, country_code: string|null, min_salary: int|null, salary_currency: string|null, salary_period: string|null}  $data
     */
    public static function make(array $data): Profile
    {
        $skills = [];

        foreach ($data['skills'] as $name => $proficiency) {
            $catalogue = Skill::findByName($name);
            $skill = $catalogue === null ? new Skill(['name' => $name]) : clone $catalogue;
            $skill->setRelation('pivot', (new UserSkill)->forceFill(['proficiency' => SkillProficiency::from($proficiency)]));
            $skills[] = $skill;
        }

        $user = new User;
        $user->setRelation('skills', new Collection($skills));

        $profile = (new Profile)->forceFill([
            'target_roles' => $data['target_roles'],
            'work_arrangements' => $data['work_arrangements'],
            'country_code' => $data['country_code'],
            'min_salary' => $data['min_salary'],
            'salary_currency' => $data['salary_currency'],
            'salary_period' => $data['salary_period'],
        ]);
        $profile->setRelation('user', $user);

        return $profile;
    }
}
