<?php

namespace App\JobSources;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Builds the postings of one fetch() run of one source from the raw values that the source maps.
 *
 * A posting that cannot be used is skipped, so one bad row never ends a run. A bad optional field is dropped and the
 * posting is kept, so a typo in a salary does not lose an otherwise good job. Every skip and every dropped field is
 * counted for the run summary and logged.
 */
final class PostingBuilder
{
    private int $yielded = 0;

    private int $skipped = 0;

    private int $droppedFields = 0;

    /**
     * Start the posting counts for one source run.
     */
    public function __construct(private string $source) {}

    /**
     * Build one posting, or skip it and return null when a required value cannot be used.
     *
     * @param  array{
     *     externalId?: mixed,
     *     title?: mixed,
     *     companyName?: mixed,
     *     applyUrl?: mixed,
     *     descriptionHtml?: mixed,
     *     postedAt?: mixed,
     *     expiresAt?: mixed,
     *     location?: mixed,
     *     remoteType?: mixed,
     *     employmentType?: mixed,
     *     seniority?: mixed,
     *     salaryMin?: mixed,
     *     salaryMax?: mixed,
     *     salaryCurrency?: mixed,
     *     salaryPeriod?: mixed,
     *     salaryText?: mixed,
     * }  $values
     */
    public function build(array $values): ?JobPostingData
    {
        $externalId = $this->externalId($values['externalId'] ?? null);

        if ($externalId === null) {
            return $this->skipPosting(null, 'missing external ID');
        }

        $title = $this->trimmedString($values['title'] ?? null);

        if ($title === null) {
            return $this->skipPosting($externalId, 'missing title');
        }

        $companyName = $this->trimmedString($values['companyName'] ?? null);

        if ($companyName === null) {
            return $this->skipPosting($externalId, 'missing company name');
        }

        $applyUrl = $this->trimmedString($values['applyUrl'] ?? null);

        if ($applyUrl === null || filter_var($applyUrl, FILTER_VALIDATE_URL) === false) {
            return $this->skipPosting($externalId, 'apply URL is not a URL');
        }

        $scheme = parse_url($applyUrl, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return $this->skipPosting($externalId, 'apply URL is not http or https');
        }

        $postedAt = $values['postedAt'] ?? null;

        if (! $postedAt instanceof CarbonImmutable) {
            return $this->skipPosting($externalId, 'missing posted date');
        }

        $expiresAt = $values['expiresAt'] ?? null;

        if (! $expiresAt instanceof CarbonImmutable) {
            return $this->skipPosting($externalId, 'missing expiry date');
        }

        if (! $expiresAt->greaterThan($postedAt)) {
            return $this->skipPosting($externalId, 'expired before update');
        }

        $salaryMin = $this->amount($externalId, 'salaryMin', $values['salaryMin'] ?? null);
        $salaryMax = $this->amount($externalId, 'salaryMax', $values['salaryMax'] ?? null);

        if ($salaryMin !== null && $salaryMax !== null && $salaryMin > $salaryMax) {
            $this->dropField($externalId, 'salaryMin', 'minimum salary above the maximum salary');
            $this->dropField($externalId, 'salaryMax', 'minimum salary above the maximum salary');

            $salaryMin = null;
            $salaryMax = null;
        }

        $salaryCurrency = $this->currency($externalId, $values['salaryCurrency'] ?? null);

        $posting = new JobPostingData(
            source: $this->source,
            externalId: $externalId,
            title: $title,
            companyName: $companyName,
            applyUrl: $applyUrl,
            descriptionHtml: $this->descriptionHtml($values['descriptionHtml'] ?? null),
            postedAt: $postedAt,
            expiresAt: $expiresAt,
            location: $this->optionalString($externalId, 'location', $values['location'] ?? null),
            remoteType: $this->optionalString($externalId, 'remoteType', $values['remoteType'] ?? null),
            employmentType: $this->optionalString($externalId, 'employmentType', $values['employmentType'] ?? null),
            seniority: $this->optionalString($externalId, 'seniority', $values['seniority'] ?? null),
            salaryMin: $salaryMin,
            salaryMax: $salaryMax,
            salaryCurrency: $salaryCurrency,
            salaryPeriod: $this->optionalString($externalId, 'salaryPeriod', $values['salaryPeriod'] ?? null),
            salaryText: $this->optionalString($externalId, 'salaryText', $values['salaryText'] ?? null),
        );

        $this->yielded++;

        return $posting;
    }

    /**
     * Get the counts and deadline stop status of the run that read the given number of pages.
     */
    public function report(int $pages, bool $stoppedEarly = false): FetchReport
    {
        return new FetchReport(
            pages: $pages,
            yielded: $this->yielded,
            skipped: $this->skipped,
            droppedFields: $this->droppedFields,
            stoppedEarly: $stoppedEarly,
        );
    }

    /**
     * Get the external ID that the posting can be stored under, or null when it has none.
     */
    private function externalId(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return $this->trimmedString($value);
    }

    /**
     * Get nonblank trimmed text, or null when the value is not usable text.
     */
    private function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Get the salary amount as a float, or null when the value is not an amount of 0 or more.
     */
    private function amount(string $externalId, string $field, mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if ((is_int($value) || is_float($value)) && $value >= 0) {
            return (float) $value;
        }

        $this->dropField($externalId, $field, 'not an amount of 0 or more');

        return null;
    }

    /**
     * Get the currency code, or null when the value is not three capital letters.
     */
    private function currency(string $externalId, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^[A-Z]{3}$/', $value) === 1) {
            return $value;
        }

        $this->dropField($externalId, 'salaryCurrency', 'not three capital letters');

        return null;
    }

    /**
     * Get the description, which is an empty string when the source sends none.
     */
    private function descriptionHtml(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Get an optional text field, or null when the value is not a string.
     */
    private function optionalString(string $externalId, string $field, mixed $value): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        $this->dropField($externalId, $field, 'not a text value');

        return null;
    }

    /**
     * Log and count a posting that the source cannot use.
     */
    private function skipPosting(?string $externalId, string $reason): null
    {
        $this->skipped++;

        Log::warning('jobs.source.posting_skipped', [
            'source' => $this->source,
            'external_id' => $externalId,
            'reason' => $reason,
        ]);

        return null;
    }

    /**
     * Log and count one bad optional field of a posting that is kept.
     */
    private function dropField(string $externalId, string $field, string $reason): void
    {
        $this->droppedFields++;

        Log::warning('jobs.source.field_dropped', [
            'source' => $this->source,
            'external_id' => $externalId,
            'field' => $field,
            'reason' => $reason,
        ]);
    }
}
