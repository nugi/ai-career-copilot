<?php

namespace Database\Factories;

use App\Models\Skill;
use App\Models\SkillAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SkillAlias>
 */
class SkillAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'skill_id' => Skill::factory(),
            'alias' => fake()->unique()->words(3, true),
        ];
    }
}
