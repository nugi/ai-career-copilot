<?php

namespace Database\Factories;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Skill>
 */
class SkillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::headline(fake()->unique()->slug(2)),
            'is_verified' => true,
        ];
    }

    /**
     * A skill a user typed that is not yet in the shared catalogue.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_verified' => false,
        ]);
    }
}
