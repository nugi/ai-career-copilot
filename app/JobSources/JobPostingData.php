<?php

namespace App\JobSources;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A posting as one job source sends it.
 *
 * The values are as the source sends them, and the constructor throws when one of them cannot be stored or shown.
 *
 * remoteType, employmentType, seniority and salaryPeriod are the source's own words, for example "Full Time",
 * "Senior" or "hourly". The normalizer maps them to the app's enums. salaryText holds a salary that the source sends
 * only as text, such as "Up to $60k". applyUrl is the link that the source requires for the Apply action.
 *
 * postedAt is the date that the source sends, which for some sources is the time of the last update. Every source must
 * set expiresAt, and ingestion expires postings by this date only.
 */
final readonly class JobPostingData
{
    public function __construct(
        public string $source,
        public string $externalId,
        public string $title,
        public string $companyName,
        public string $applyUrl,
        public string $descriptionHtml,
        public CarbonImmutable $postedAt,
        public CarbonImmutable $expiresAt,
        public ?string $location = null,
        public ?string $remoteType = null,
        public ?string $employmentType = null,
        public ?string $seniority = null,
        public ?float $salaryMin = null,
        public ?float $salaryMax = null,
        public ?string $salaryCurrency = null,
        public ?string $salaryPeriod = null,
        public ?string $salaryText = null,
    ) {
        $this->ensureValid();
    }

    /**
     * Throw when a value is missing or cannot be used, so a bad row fails at the edge of the source.
     */
    private function ensureValid(): void
    {
        if (trim($this->source) === '') {
            throw new InvalidArgumentException($this->invalidMessage('an empty source'));
        }

        if (trim($this->externalId) === '') {
            throw new InvalidArgumentException($this->invalidMessage('an empty external ID'));
        }

        if (trim($this->title) === '') {
            throw new InvalidArgumentException($this->invalidMessage('an empty title'));
        }

        if (trim($this->companyName) === '') {
            throw new InvalidArgumentException($this->invalidMessage('an empty company name'));
        }

        $scheme = parse_url($this->applyUrl, PHP_URL_SCHEME);

        if (filter_var($this->applyUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException($this->invalidMessage('an apply URL that is not a URL'));
        }

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new InvalidArgumentException($this->invalidMessage('an apply URL that is not http or https'));
        }

        if (! $this->expiresAt->greaterThan($this->postedAt)) {
            throw new InvalidArgumentException($this->invalidMessage('an expiry date that is not after its posted date'));
        }

        if ($this->salaryMin !== null && $this->salaryMin < 0) {
            throw new InvalidArgumentException($this->invalidMessage('a negative minimum salary'));
        }

        if ($this->salaryMax !== null && $this->salaryMax < 0) {
            throw new InvalidArgumentException($this->invalidMessage('a negative maximum salary'));
        }

        if ($this->salaryMin !== null && $this->salaryMax !== null && $this->salaryMin > $this->salaryMax) {
            throw new InvalidArgumentException($this->invalidMessage('a minimum salary above the maximum salary'));
        }

        if ($this->salaryCurrency !== null && preg_match('/^[A-Z]{3}$/', $this->salaryCurrency) !== 1) {
            throw new InvalidArgumentException($this->invalidMessage('an invalid salary currency'));
        }
    }

    /**
     * Build the message that names the posting and the value that failed.
     */
    private function invalidMessage(string $problem): string
    {
        return sprintf('Job posting [%s:%s] has %s.', $this->source, $this->externalId, $problem);
    }
}
