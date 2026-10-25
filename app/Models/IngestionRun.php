<?php

namespace App\Models;

use App\Enums\IngestionRunStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IngestionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $source
 * @property IngestionRunStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int $pages
 * @property int $fetched
 * @property int $skipped
 * @property int $dropped_fields
 * @property int $created
 * @property int $updated
 * @property int $unchanged
 * @property int $links_changed
 * @property bool $stopped_early
 * @property int|null $duration_ms
 * @property string|null $error The exception class and message, cut to 255 characters.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'source', 'status', 'started_at', 'finished_at', 'pages', 'fetched', 'skipped', 'dropped_fields',
    'created', 'updated', 'unchanged', 'links_changed', 'stopped_early', 'duration_ms', 'error',
])]
class IngestionRun extends Model
{
    /** @use HasFactory<IngestionRunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IngestionRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pages' => 'integer',
            'fetched' => 'integer',
            'skipped' => 'integer',
            'dropped_fields' => 'integer',
            'created' => 'integer',
            'updated' => 'integer',
            'unchanged' => 'integer',
            'links_changed' => 'integer',
            'stopped_early' => 'boolean',
            'duration_ms' => 'integer',
        ];
    }
}
