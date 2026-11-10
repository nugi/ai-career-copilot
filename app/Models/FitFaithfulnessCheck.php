<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FitFaithfulnessCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $job_posting_id
 * @property string $prompt_version
 * @property string $judge_prompt_version
 * @property int $claims
 * @property int $supported
 * @property int $unsupported
 * @property int $unjudged
 * @property string|null $score
 * @property CarbonImmutable $created_at
 * @property-read JobPosting|null $jobPosting
 */
#[Fillable(['job_posting_id', 'prompt_version', 'judge_prompt_version', 'claims', 'supported', 'unsupported', 'unjudged', 'score'])]
class FitFaithfulnessCheck extends Model
{
    /** @use HasFactory<FitFaithfulnessCheckFactory> */
    use HasFactory;

    /**
     * Faithfulness checks are append-only, so they have no update timestamp.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'job_posting_id' => 'integer',
            'claims' => 'integer',
            'supported' => 'integer',
            'unsupported' => 'integer',
            'unjudged' => 'integer',
            'score' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}
