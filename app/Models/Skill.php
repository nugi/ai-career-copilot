<?php

namespace App\Models;

use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $normalized_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, SkillAlias> $aliases
 * @property-read Collection<int, JobPosting> $jobPostings
 * @property-read Collection<int, User> $users
 */
#[Fillable(['name'])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    /**
     * Normalize a skill name or alias for lookups: trimmed, single-spaced and lowercase.
     */
    public static function normalize(string $name): string
    {
        return Str::of($name)->squish()->lower()->toString();
    }

    /**
     * Keep the normalized name in sync with the display name.
     *
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'name' => Str::squish($value),
                'normalized_name' => static::normalize($value),
            ],
        );
    }

    /**
     * @return HasMany<SkillAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(SkillAlias::class);
    }

    /**
     * @return BelongsToMany<JobPosting, $this, Pivot>
     */
    public function jobPostings(): BelongsToMany
    {
        return $this->belongsToMany(JobPosting::class)->withPivot('is_required');
    }

    /**
     * @return BelongsToMany<User, $this, UserSkill>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_skills')
            ->using(UserSkill::class)
            ->withPivot('proficiency')
            ->withTimestamps();
    }
}
