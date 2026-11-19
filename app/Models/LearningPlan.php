<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\LearningPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property list<array{skill: string, weight: float, required_jobs: int, nice_jobs: int, jobs: int, of: int}> $gaps
 * @property array{summary: string, weeks: list<array{week: int, goal: string, skills: list<string>, tasks: list<string>}>, project: array{title: string, description: string, skills: list<string>}, showcase: array{resume_bullet: string, linkedin: string}, dropped_skills: int} $plan
 * @property string $prompt_version
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'gaps', 'plan', 'prompt_version', 'generated_at'])]
class LearningPlan extends Model
{
    /** @use HasFactory<LearningPlanFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gaps' => 'array',
            'plan' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
