<?php

namespace App\Models;

use App\Enums\SkillProficiency;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property int $skill_id
 * @property SkillProficiency $proficiency
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
        ];
    }
}
