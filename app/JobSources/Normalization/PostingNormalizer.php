<?php

namespace App\JobSources\Normalization;

use App\Enums\EmploymentType;
use App\Enums\SeniorityLevel;
use App\Enums\WorkArrangement;
use App\JobSources\JobPostingData;
use Illuminate\Support\Facades\Log;

/**
 * Maps a posting as the source sent it to the app's enums, units and places, and computes its duplicate key.
 *
 * Pay stays in the posted currency and period, with annual figures beside it (ADR 0017). A posting whose work
 * arrangement is unknown is skipped, and an optional value that cannot be mapped is dropped and logged, as in
 * PostingBuilder.
 */
final class PostingNormalizer
{
    public const int NORMALIZATION_VERSION = 1;

    /**
     * Compose pure parsers without requiring the container or a database connection.
     */
    public function __construct(
        private LocationParser $locations = new LocationParser,
        private SalaryTextParser $salaryText = new SalaryTextParser,
    ) {}

    /**
     * Normalize source data into a row, skipping only an unknown work arrangement.
     */
    public function normalize(JobPostingData $posting): ?NormalizedPosting
    {
        $workArrangement = match (strtolower(self::whitespace($posting->remoteType ?? ''))) {
            'remote', 'fully remote', 'anywhere' => WorkArrangement::Remote,
            'hybrid' => WorkArrangement::Hybrid,
            'onsite', 'on-site', 'on site', 'office', 'in office' => WorkArrangement::Onsite,
            default => null,
        };

        if ($workArrangement === null) {
            Log::warning('jobs.source.posting_skipped', [
                'source' => $posting->source,
                'external_id' => $posting->externalId,
                'reason' => 'unknown remote type',
            ]);

            return null;
        }

        $title = self::whitespace($posting->title);
        $companyName = self::whitespace($posting->companyName);
        $location = self::whitespace($posting->location ?? '');
        $parsedLocation = $this->locations->parse($location);
        $countryCode = count($parsedLocation->countryCodes) === 1 ? $parsedLocation->countryCodes[0] : null;
        $salary = $this->salary($posting);
        $annualFactor = $salary->period?->annualFactor($countryCode);
        $normalizedCompany = DuplicateKey::company($companyName);
        $normalizedTitle = DuplicateKey::title($title);
        $locationKey = $parsedLocation->key();

        return new NormalizedPosting(
            source: $posting->source,
            externalId: $posting->externalId,
            title: $title,
            companyName: $companyName,
            description: HtmlText::from($posting->descriptionHtml),
            location: $location === '' ? null : $location,
            city: $parsedLocation->city,
            countryCode: $countryCode,
            countryCodes: $parsedLocation->countryCodes,
            region: $parsedLocation->region,
            workArrangement: $workArrangement,
            employmentType: $this->employmentType($posting),
            seniority: $this->seniority($posting),
            salaryMin: self::rounded($salary->min),
            salaryMax: self::rounded($salary->max),
            salaryCurrency: $salary->currency,
            salaryPeriod: $salary->period,
            annualSalaryMin: $annualFactor === null ? null : self::rounded($salary->min === null ? null : $salary->min * $annualFactor),
            annualSalaryMax: $annualFactor === null ? null : self::rounded($salary->max === null ? null : $salary->max * $annualFactor),
            salaryNormalizationVersion: $salary->min !== null || $salary->max !== null ? self::NORMALIZATION_VERSION : null,
            applyUrl: $posting->applyUrl,
            postedAt: $posting->postedAt,
            expiresAt: $posting->expiresAt,
            normalizedCompany: $normalizedCompany,
            normalizedTitle: $normalizedTitle,
            locationKey: $locationKey,
            duplicateKey: DuplicateKey::hash($normalizedCompany, $normalizedTitle, $locationKey),
        );
    }

    /**
     * Keep the first recognized employment type from the source's ordered list.
     */
    private function employmentType(JobPostingData $posting): ?EmploymentType
    {
        if ($posting->employmentType === null) {
            return null;
        }

        foreach (explode(',', $posting->employmentType) as $part) {
            $type = match (strtolower(self::whitespace($part))) {
                'full time', 'full-time', 'fulltime', 'permanent' => EmploymentType::FullTime,
                'part time', 'part-time' => EmploymentType::PartTime,
                'contract', 'contractor', 'temporary', 'temp' => EmploymentType::Contract,
                'freelance', 'freelancer' => EmploymentType::Freelance,
                'intern', 'internship' => EmploymentType::Internship,
                default => null,
            };

            if ($type !== null) {
                return $type;
            }
        }

        $this->dropField($posting, 'employmentType', 'unknown employment type');

        return null;
    }

    /**
     * Keep the lowest recognized level; an explicit any is not a dropped field.
     */
    private function seniority(JobPostingData $posting): ?SeniorityLevel
    {
        if ($posting->seniority === null) {
            return null;
        }

        $levels = [];
        $any = false;

        foreach (explode(',', $posting->seniority) as $part) {
            $part = strtolower(self::whitespace($part));
            $any = $any || $part === 'any';
            $level = match ($part) {
                'intern', 'entry', 'entry level', 'entry-level', 'junior', 'graduate' => SeniorityLevel::Junior,
                'mid', 'mid level', 'mid-level', 'midweight', 'intermediate' => SeniorityLevel::Mid,
                'senior' => SeniorityLevel::Senior,
                'lead', 'staff', 'principal', 'manager', 'director', 'head', 'executive', 'vp' => SeniorityLevel::Lead,
                default => null,
            };

            if ($level !== null) {
                $levels[] = $level;
            }
        }

        foreach ([SeniorityLevel::Junior, SeniorityLevel::Mid, SeniorityLevel::Senior, SeniorityLevel::Lead] as $level) {
            if (in_array($level, $levels, true)) {
                return $level;
            }
        }

        if (! $any) {
            $this->dropField($posting, 'seniority', 'unknown seniority');
        }

        return null;
    }

    /**
     * Use salary text only when both source amounts are absent, with its own units.
     */
    private function salary(JobPostingData $posting): SalaryText
    {
        $period = $posting->salaryPeriod === null ? null : SalaryTextParser::period($posting->salaryPeriod);

        if ($posting->salaryPeriod !== null && $period === null) {
            $this->dropField($posting, 'salaryPeriod', 'unknown salary period');
        }

        if ($posting->salaryMin === null && $posting->salaryMax === null) {
            return ($posting->salaryText === null ? null : $this->salaryText->parse($posting->salaryText))
                ?? new SalaryText(null, null, null, null);
        }

        return new SalaryText($posting->salaryMin, $posting->salaryMax, $posting->salaryCurrency, $period);
    }

    /**
     * Round only the final posted or annual amount, preserving a missing range end.
     */
    private static function rounded(?float $amount): ?int
    {
        return $amount === null ? null : (int) round($amount);
    }

    /**
     * Collapse Unicode whitespace while keeping source spelling and case.
     */
    private static function whitespace(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * Log one optional value that cannot be mapped, without discarding the posting.
     */
    private function dropField(JobPostingData $posting, string $field, string $reason): void
    {
        Log::warning('jobs.source.field_dropped', [
            'source' => $posting->source,
            'external_id' => $posting->externalId,
            'field' => $field,
            'reason' => $reason,
        ]);
    }
}
