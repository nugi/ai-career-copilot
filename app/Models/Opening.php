<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OpeningFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The identity of one job. Its postings come from one or more sources, and it keeps its ID while any of them is stored
 * (ADR 0019).
 *
 * @property int $id
 * @property int|null $merged_into_id The opening that absorbed this one when the two groups became one job.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, JobPosting> $postings
 * @property-read Opening|null $mergedInto
 */
#[Fillable(['merged_into_id'])]
class Opening extends Model
{
    /** @use HasFactory<OpeningFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merged_into_id' => 'integer',
        ];
    }

    /**
     * Openings with at least one live posting. Lists, matching and embeddings read only these.
     *
     * @param  Builder<Opening>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereHas('postings', fn (Builder $postings) => $postings->live());
    }

    /**
     * @return HasMany<JobPosting, $this>
     */
    public function postings(): HasMany
    {
        return $this->hasMany(JobPosting::class);
    }

    /**
     * @return BelongsTo<Opening, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }
}
