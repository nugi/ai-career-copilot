<?php

namespace Database\Factories;

use App\Enums\IngestionRunStatus;
use App\Models\IngestionRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IngestionRun>
 */
class IngestionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 day');

        return [
            'source' => 'fake',
            'status' => IngestionRunStatus::Succeeded,
            'started_at' => $startedAt,
            'finished_at' => $startedAt,
            'pages' => 1,
            'fetched' => 3,
            'skipped' => 0,
            'dropped_fields' => 0,
            'created' => 3,
            'updated' => 0,
            'unchanged' => 0,
            'links_changed' => 0,
            'stopped_early' => false,
            'duration_ms' => 120,
            'error' => null,
        ];
    }
}
