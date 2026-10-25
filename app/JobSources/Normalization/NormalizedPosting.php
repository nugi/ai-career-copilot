<?php

namespace App\JobSources\Normalization;

use App\Enums\EmploymentType;
use App\Enums\JobRegion;
use App\Enums\SalaryPeriod;
use App\Enums\SeniorityLevel;
use App\Enums\WorkArrangement;
use Carbon\CarbonImmutable;

/**
 * A posting in the app's own enums and units, ready to be stored as one job_postings row.
 */
final readonly class NormalizedPosting
{
    /**
     * Hold a normalized row and the duplicate keys that are not yet persisted.
     *
     * @param  list<string>  $countryCodes
     */
    public function __construct(
        public string $source,
        public string $externalId,
        public string $title,
        public string $companyName,
        public string $description,
        public ?string $location,
        public ?string $city,
        public ?string $countryCode,
        public array $countryCodes,
        public ?JobRegion $region,
        public WorkArrangement $workArrangement,
        public ?EmploymentType $employmentType,
        public ?SeniorityLevel $seniority,
        public ?int $salaryMin,
        public ?int $salaryMax,
        public ?string $salaryCurrency,
        public ?SalaryPeriod $salaryPeriod,
        public ?int $annualSalaryMin,
        public ?int $annualSalaryMax,
        public ?int $salaryNormalizationVersion,
        public string $applyUrl,
        public CarbonImmutable $postedAt,
        public CarbonImmutable $expiresAt,
        public string $normalizedCompany,
        public string $normalizedTitle,
        public string $locationKey,
        public string $duplicateKey,
    ) {}

    /**
     * Export only persisted job_postings attributes, with enum backing values.
     *
     * @return array{
     *     source: string, external_id: string, title: string, company_name: string,
     *     description: string, location: ?string, city: ?string, country_code: ?string,
     *     country_codes: list<string>, region: ?string, work_arrangement: string,
     *     employment_type: ?string, seniority: ?string, salary_min: ?int, salary_max: ?int,
     *     salary_currency: ?string, salary_period: ?string, annual_salary_min: ?int,
     *     annual_salary_max: ?int, salary_normalization_version: ?int, apply_url: string,
     *     posted_at: CarbonImmutable, expires_at: CarbonImmutable,
     *     normalized_company: string, normalized_title: string, location_key: string, duplicate_key: string,
     *     content_hash: string, text_hash: string
     * }
     */
    public function toAttributes(): array
    {
        $attributes = [
            'source' => $this->source,
            'external_id' => $this->externalId,
            'title' => $this->title,
            'company_name' => $this->companyName,
            'description' => $this->description,
            'location' => $this->location,
            'city' => $this->city,
            'country_code' => $this->countryCode,
            'country_codes' => $this->countryCodes,
            'region' => $this->region?->value,
            'work_arrangement' => $this->workArrangement->value,
            'employment_type' => $this->employmentType?->value,
            'seniority' => $this->seniority?->value,
            'salary_min' => $this->salaryMin,
            'salary_max' => $this->salaryMax,
            'salary_currency' => $this->salaryCurrency,
            'salary_period' => $this->salaryPeriod?->value,
            'annual_salary_min' => $this->annualSalaryMin,
            'annual_salary_max' => $this->annualSalaryMax,
            'salary_normalization_version' => $this->salaryNormalizationVersion,
            'apply_url' => $this->applyUrl,
            'posted_at' => $this->postedAt,
            'expires_at' => $this->expiresAt,
            'normalized_company' => $this->normalizedCompany,
            'normalized_title' => $this->normalizedTitle,
            'location_key' => $this->locationKey,
            'duplicate_key' => $this->duplicateKey,
        ];

        $attributes['content_hash'] = self::contentHash($attributes);
        $attributes['text_hash'] = self::textHash($this->title, $this->description);

        return $attributes;
    }

    /**
     * Hash a normalized row as it is stored, with the ISO timestamps that the timestamp columns keep.
     *
     * @param  array{
     *     source: string, external_id: string, title: string, company_name: string,
     *     description: string, location: ?string, city: ?string, country_code: ?string,
     *     country_codes: list<string>, region: ?string, work_arrangement: string,
     *     employment_type: ?string, seniority: ?string, salary_min: ?int, salary_max: ?int,
     *     salary_currency: ?string, salary_period: ?string, annual_salary_min: ?int,
     *     annual_salary_max: ?int, salary_normalization_version: ?int, apply_url: string,
     *     posted_at: CarbonImmutable, expires_at: CarbonImmutable,
     *     normalized_company: string, normalized_title: string, location_key: string, duplicate_key: string
     * }  $attributes
     */
    public static function contentHash(array $attributes): string
    {
        $hashed = $attributes;
        $hashed['posted_at'] = $attributes['posted_at']->toIso8601String();
        $hashed['expires_at'] = $attributes['expires_at']->toIso8601String();

        return hash('sha256', json_encode($hashed, JSON_THROW_ON_ERROR));
    }

    /**
     * Hash the posting text that later AI work, such as skill extraction and embeddings, is keyed on.
     */
    public static function textHash(string $title, string $description): string
    {
        return hash('sha256', json_encode([$title, $description], JSON_THROW_ON_ERROR));
    }
}
