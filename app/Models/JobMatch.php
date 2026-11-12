<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\JobMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $job_posting_id
 * @property int|null $resume_id
 * @property int $score 0 to 100.
 * @property string|null $scoring_version
 * @property array<string, array{value: float|null, weight: int, detail: array<string, mixed>}> $breakdown
 * @property array<string, mixed> $inputs
 * @property list<string> $matched_skills
 * @property list<string> $missing_skills
 * @property int|null $profile_version
 * @property list<string> $reasons
 * @property CarbonImmutable $scored_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read JobPosting $jobPosting
 * @property-read Resume|null $resume
 */
#[Fillable(['job_posting_id', 'resume_id', 'score', 'reasons', 'scored_at', 'scoring_version', 'breakdown', 'inputs', 'matched_skills', 'missing_skills', 'profile_version'])]
class JobMatch extends Model
{
    /** @use HasFactory<JobMatchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'breakdown' => 'array',
            'inputs' => 'array',
            'matched_skills' => 'array',
            'missing_skills' => 'array',
            'profile_version' => 'integer',
            'reasons' => 'array',
            'scored_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<JobPosting, $this>
     */
    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    /**
     * @return BelongsTo<Resume, $this>
     */
    public function resume(): BelongsTo
    {
        return $this->belongsTo(Resume::class);
    }
}
