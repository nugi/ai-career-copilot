<?php

namespace App\Actions\Fit;

/**
 * A fit explanation whose every reason cites at least one retrieved resume excerpt, or the reason there is none.
 *
 * @phpstan-type Excerpt array{id: int, section: string, content: string}
 * @phpstan-type Reason array{claim: string, excerpts: list<Excerpt>}
 * @phpstan-type Status 'ready'|'no_resume'|'resume_not_ready'|'budget'|'failed'
 * @phpstan-type Verdict 'strong_fit'|'partial_fit'|'weak_fit'
 * @phpstan-type Data array{status: Status, verdict: Verdict|null, summary: string, reasons: list<Reason>, missing_requirements: list<string>, dropped_reasons: int, downgraded: bool}
 */
final readonly class FitExplanation
{
    /**
     * @param  'ready'|'no_resume'|'resume_not_ready'|'budget'|'failed'  $status
     * @param  'strong_fit'|'partial_fit'|'weak_fit'|null  $verdict
     * @param  list<Reason>  $reasons
     * @param  list<string>  $missingRequirements
     */
    public function __construct(
        public string $status,
        public ?string $verdict = null,
        public string $summary = '',
        public array $reasons = [],
        public array $missingRequirements = [],
        public int $droppedReasons = 0,
        public bool $downgraded = false,
    ) {}

    /**
     * An explanation that could not be made, for the given reason.
     *
     * @param  'no_resume'|'resume_not_ready'|'budget'|'failed'  $status
     */
    public static function unavailable(string $status): self
    {
        return new self($status);
    }

    /**
     * Rebuild an explanation from its cached array.
     *
     * @param  Data  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['status'], $data['verdict'], $data['summary'], $data['reasons'],
            $data['missing_requirements'], $data['dropped_reasons'], $data['downgraded'],
        );
    }

    /**
     * Get the explanation as the array that the page and the cache use.
     *
     * @return Data
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'verdict' => $this->verdict,
            'summary' => $this->summary,
            'reasons' => $this->reasons,
            'missing_requirements' => $this->missingRequirements,
            'dropped_reasons' => $this->droppedReasons,
            'downgraded' => $this->downgraded,
        ];
    }
}
