<?php

namespace Database\Factories;

use App\Ai\Agents\ResumeParser;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Resume>
 */
class ResumeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->randomElement(['Backend CV', 'Full stack CV', 'CV 2026']),
            'file_path' => 'resumes/'.Str::uuid().'.pdf',
            'file_name' => 'cv-'.Str::slug(fake()->name()).'.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => fake()->numberBetween(50_000, 500_000),
            'content' => null,
            'is_primary' => false,
            'parsed_at' => null,
        ];
    }

    /**
     * The resume used for fit scoring.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_primary' => true,
        ]);
    }

    /**
     * A resume whose text has been extracted from the PDF. The structured parse has not run, so parsed_at stays null.
     */
    public function extracted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'content' => implode("\n\n", [
                'Backend engineer with 5 years of experience building Laravel applications for e-commerce and fintech teams in Jakarta.',
                'Experience: designed a PostgreSQL schema for 2M orders; moved queues to Redis; cut p95 API latency from 800 ms to 180 ms.',
                'Skills: PHP, Laravel, PostgreSQL, Redis, Docker, AWS.',
                'Education: S1 Teknik Informatika, Universitas Gadjah Mada.',
            ]),
        ]);
    }

    /**
     * A resume whose text has been extracted and whose structured parse has succeeded.
     */
    public function parsed(): static
    {
        return $this->extracted()->state(fn (array $attributes): array => [
            'parsed_data' => [
                'summary' => null,
                'skills' => ['Docker', 'Laravel', 'PHP', 'PostgreSQL', 'Redis'],
                'experiences' => [[
                    'title' => 'Backend Engineer',
                    'company' => 'Acme Corp',
                    'start' => '2019-01',
                    'end' => '2021-12',
                    'bullets' => ['Designed a PostgreSQL schema for 2M orders'],
                ]],
                'education' => [[
                    'institution' => 'Universitas Gadjah Mada',
                    'degree' => 'S1',
                    'field' => 'Teknik Informatika',
                    'start' => '2014',
                    'end' => '2018',
                ]],
                'checks' => [
                    'model_years_of_experience' => 3.0,
                    'years_mismatch' => false,
                    'ungrounded_skills' => [],
                    'ungrounded_bullets' => [],
                ],
            ],
            'prompt_version' => ResumeParser::PROMPT_VERSION,
            'parsed_at' => now(),
        ]);
    }

    /**
     * A resume whose structured parse failed, for example because the AI provider was unavailable.
     */
    public function parseFailed(): static
    {
        return $this->extracted()->state(fn (array $attributes): array => [
            'parse_failed_at' => now(),
            'parse_errors' => [['path' => '*', 'rule' => 'ProviderUnavailable']],
        ]);
    }
}
