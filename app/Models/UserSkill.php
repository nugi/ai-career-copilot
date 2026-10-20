<?php

namespace App\Models;

use App\Enums\SkillProficiency;
use App\Enums\SkillSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $user_id
 * @property int $skill_id
 * @property SkillProficiency|null $proficiency
 * @property SkillSource $source
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class UserSkill extends Pivot
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_skills';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proficiency' => SkillProficiency::class,
            'source' => SkillSource::class,
        ];
    }
}
