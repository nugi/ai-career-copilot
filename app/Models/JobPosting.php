<?php

namespace App\Models;

use App\Enums\EmploymentType;
use App\Enums\JobRegion;
use App\Enums\SalaryPeriod;
use App\Enums\SeniorityLevel;
use App\Enums\WorkArrangement;
use Carbon\CarbonImmutable;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property string $source
 * @property string|null $external_id
 * @property string $title
 * @property string $company_name
 * @property string $description
 * @property string|null $location
 * @property string|null $country_code ISO 3166-1 alpha-2; null for remote roles open worldwide.
 * @property WorkArrangement $work_arrangement
 * @property EmploymentType|null $employment_type
 * @property SeniorityLevel|null $seniority
 * @property int|null $salary_min Whole currency units, not cents.
 * @property int|null $salary_max Whole currency units, not cents.
 * @property string|null $salary_currency ISO 4217, as posted
 * @property SalaryPeriod|null $salary_period
 * @property int|null $annual_salary_min Whole units of salary_currency per year; see ADR 0017.
 * @property int|null $annual_salary_max
 * @property int|null $salary_normalization_version
 * @property string|null $city
 * @property list<string> $country_codes ISO 3166-1 alpha-2 codes a candidate may live in; empty when unknown or worldwide.
 * @property JobRegion|null $region
 * @property string $apply_url
 * @property CarbonImmutable $posted_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Skill&object{pivot: Pivot}> $skills
 * @property-read Collection<int, JobMatch> $matches
 */
#[Fillable([
    'source', 'external_id', 'title', 'company_name', 'description', 'location', 'country_code',
    'work_arrangement', 'employment_type', 'seniority', 'salary_min', 'salary_max', 'salary_currency',
    'salary_period', 'annual_salary_min', 'annual_salary_max', 'salary_normalization_version',
    'city', 'country_codes', 'region', 'apply_url', 'posted_at', 'expires_at',
])]
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_arrangement' => WorkArrangement::class,
            'employment_type' => EmploymentType::class,
            'seniority' => SeniorityLevel::class,
            'salary_min' => 'integer',
            'salary_max' => 'integer',
            'salary_period' => SalaryPeriod::class,
            'annual_salary_min' => 'integer',
            'annual_salary_max' => 'integer',
            'salary_normalization_version' => 'integer',
            'country_codes' => 'array',
            'region' => JobRegion::class,
            'posted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Skill, $this, Pivot>
     */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->withPivot('is_required');
    }

    /**
     * @return HasMany<JobMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(JobMatch::class);
    }
}
