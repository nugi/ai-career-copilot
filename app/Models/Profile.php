<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\SalaryPeriod;
use App\Enums\WorkArrangement;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['headline', 'target_roles', 'preferred_locations', 'work_arrangements', 'min_salary', 'salary_currency', 'salary_period'])]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

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
