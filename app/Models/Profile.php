<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use Carbon\CarbonImmutable;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $headline
 * @property list<string> $target_roles
 * @property list<string> $preferred_locations
 * @property Collection<int, WorkArrangement> $work_arrangements
 * @property int|null $min_salary Whole currency units, not cents.
 * @property Currency|null $salary_currency
 * @property SalaryPeriod|null $salary_period
 * @property int $version Increases whenever preferences or skills change, so caches keyed on it go stale.
 * @property CarbonImmutable|null $completed_at When the user finished onboarding.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['headline', 'target_roles', 'preferred_locations', 'work_arrangements', 'min_salary', 'salary_currency', 'salary_period'])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_roles' => 'array',
            'preferred_locations' => 'array',
            'work_arrangements' => AsEnumCollection::of(WorkArrangement::class),
            'min_salary' => 'integer',
            'salary_currency' => Currency::class,
            'salary_period' => SalaryPeriod::class,
            'version' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Determine whether the user has finished onboarding.
     */
    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
