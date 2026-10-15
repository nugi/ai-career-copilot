<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $normalized_name
 * @property bool $is_verified Whether the skill is in the shared catalogue. Skills users type stay unverified, visible only in their own lists, until promoted.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, SkillAlias> $aliases
 * @property-read Collection<int, JobPosting&object{pivot: Pivot}> $jobPostings
 * @property-read Collection<int, User&object{pivot: UserSkill}> $users
 */
#[Fillable(['name'])]
class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_verified' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
        ];
    }

    /**
     * Normalize a skill name or alias for lookups: trimmed, single-spaced and lowercase.
     */
    public static function normalize(string $name): string
    {
        return Str::of($name)->squish()->lower()->toString();
    }

    /**
     * Find the skill for a name the user typed, matching its name first and then its aliases.
     * An unknown name becomes a new unverified skill, so a typo never enters the shared catalogue.
     */
    public static function findOrCreateByName(string $name): self
    {
        $normalized = static::normalize($name);

        return static::query()->where('normalized_name', $normalized)->first()
            ?? SkillAlias::query()->where('alias', $normalized)->first()->skill
            ?? static::query()->createOrFirst(['normalized_name' => $normalized], ['name' => $name]);
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
