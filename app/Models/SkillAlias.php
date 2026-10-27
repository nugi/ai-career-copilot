<?php

namespace App\Models;

use App\Jobs\RematchSkills;
use Carbon\CarbonImmutable;
use Database\Factories\SkillAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $skill_id
 * @property string $alias
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Skill $skill
 */
#[Fillable(['alias'])]
class SkillAlias extends Model
{
    /** @use HasFactory<SkillAliasFactory> */
    use HasFactory;

    /**
     * Queue resolution when an alias of a verified skill is added or changed, so stored unknown names can attach.
     */
    protected static function booted(): void
    {
        static::saved(function (SkillAlias $alias): void {
            if (($alias->wasRecentlyCreated || $alias->wasChanged(['alias', 'skill_id'])) && $alias->skill()->where('is_verified', true)->exists()) {
                RematchSkills::dispatch()->afterCommit();
            }
        });
    }

    /**
     * Store aliases in normalized form so lookups match regardless of case and spacing.
     *
     * @return Attribute<string, string>
     */
    protected function alias(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => Skill::normalize($value),
        );
    }

    /**
     * @return BelongsTo<Skill, $this>
     */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
