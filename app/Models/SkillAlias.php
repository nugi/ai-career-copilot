<?php

namespace App\Models;

use Database\Factories\SkillAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $skill_id
 * @property string $alias
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Skill $skill
 */
#[Fillable(['alias'])]
class SkillAlias extends Model
{
    /** @use HasFactory<SkillAliasFactory> */
    use HasFactory;

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
