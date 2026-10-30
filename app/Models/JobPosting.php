<?php

namespace App\Models;

use App\Ai\Agents\JobSkillExtractor;
use App\Enums\EmploymentType;
use App\Enums\JobRegion;
use App\Enums\SalaryPeriod;
use App\Enums\SeniorityLevel;
use App\Enums\WorkArrangement;
use Carbon\CarbonImmutable;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property string $source
 * @property string|null $external_id
 * @property string $title
 * @property string $company_name
 * @property string|null $normalized_company
 * @property string|null $normalized_title
 * @property string|null $location_key
 * @property string|null $duplicate_key
 * @property int|null $opening_id Set by the import in the same transaction that stores the row.
 * @property string|null $duplicate_reason Why the row was linked: "exact" key or "trigram" title match.
 * @property float|null $duplicate_similarity Rounded title similarity when the reason is "trigram".
 * @property-read Opening|null $opening
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
 * @property CarbonImmutable $first_seen_at When an import first stored the row; it never changes.
 * @property CarbonImmutable $last_seen_at When an import last returned the row.
 * @property string|null $content_hash SHA-256 of the normalized row; set on its next import when null.
 * @property string|null $text_hash SHA-256 of the title and description; AI work on the posting text is keyed on it.
 * @property array{required: list<string>, nice_to_have: list<string>, unknown: list<array{name: string, normalized: string, required: bool}>, ungrounded: list<string>}|null $extracted_skills The saved extraction: required, nice_to_have and ungrounded are lists of names; unknown keeps each name, normalized name and required group.
 * @property SeniorityLevel|null $extracted_seniority The seniority that the posting text asks for; the source seniority stays in seniority.
 * @property int|null $years_required The minimum years of experience that the posting text asks for.
 * @property string|null $skills_text_hash The text_hash that the extraction or the failure is for.
 * @property string|null $skills_prompt_version The prompt version of the extraction or the failure.
 * @property CarbonImmutable|null $skills_extracted_at
 * @property CarbonImmutable|null $skills_failed_at When the extraction failed validation twice.
 * @property string|null $embedding pgvector text form, not cast.
 * @property string|null $embedded_text The exact text of the vector
 * @property string|null $embedding_model
 * @property CarbonImmutable|null $embedded_at
 * @property string|null $embedding_text_hash SHA-256 of the embedded text; current when this hash and the model match.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, Skill&object{pivot: Pivot&object{is_required: bool}}> $skills
 * @property-read Collection<int, JobMatch> $matches
 */
#[Fillable([
    'source', 'external_id', 'title', 'company_name', 'description', 'location', 'country_code',
    'work_arrangement', 'employment_type', 'seniority', 'salary_min', 'salary_max', 'salary_currency',
    'salary_period', 'annual_salary_min', 'annual_salary_max', 'salary_normalization_version',
    'city', 'country_codes', 'region', 'apply_url', 'posted_at', 'expires_at',
    'first_seen_at', 'last_seen_at', 'content_hash', 'text_hash',
    'normalized_company', 'normalized_title', 'location_key', 'duplicate_key', 'opening_id',
    'duplicate_reason', 'duplicate_similarity',
    'extracted_skills', 'extracted_seniority', 'years_required',
    'skills_text_hash', 'skills_prompt_version', 'skills_extracted_at', 'skills_failed_at',
    'embedding', 'embedding_model', 'embedded_at', 'embedding_text_hash', 'embedded_text',
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
            'opening_id' => 'integer',
            'duplicate_similarity' => 'float',
            'region' => JobRegion::class,
            'extracted_skills' => 'array',
            'extracted_seniority' => SeniorityLevel::class,
            'years_required' => 'integer',
            'skills_extracted_at' => 'datetime',
            'skills_failed_at' => 'datetime',
            'embedded_at' => 'datetime',
            'posted_at' => 'datetime',
            'expires_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Postings that have not expired.
     *
     * @param  Builder<JobPosting>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Live postings without a current extraction (ADR 0024).
     *
     * A recorded failure with the same text hash and prompt version counts too, so a text that the model cannot answer is not sent again.
     *
     * @param  Builder<JobPosting>  $query
     */
    #[Scope]
    protected function needsSkills(Builder $query): void
    {
        $query->live()
            ->whereRaw('(skills_text_hash is distinct from text_hash or skills_prompt_version is distinct from ?)', [
                JobSkillExtractor::PROMPT_VERSION,
            ]);
    }

    /**
     * @return BelongsTo<Opening, $this>
     */
    public function opening(): BelongsTo
    {
        return $this->belongsTo(Opening::class);
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
