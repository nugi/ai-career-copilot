<?php

namespace Database\Factories;

use App\Enums\ResumeSection;
use App\Models\Resume;
use App\Models\ResumeChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResumeChunk>
 */
class ResumeChunkFactory extends Factory
{
    /**
     * The next chunk position, so chunks made for the same resume do not collide.
     */
    protected static int $position = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraph(4);

        return [
            'resume_id' => Resume::factory(),
            'position' => static::$position++,
            'section' => fake()->randomElement(ResumeSection::cases()),
            'content' => $content,
            'token_count' => (int) ceil(str_word_count($content) * 1.3),
        ];
    }
}
