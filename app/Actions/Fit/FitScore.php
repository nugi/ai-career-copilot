<?php

namespace App\Actions\Fit;

/**
 * @phpstan-type Factor array{value: float|null, weight: int, detail: array<string, mixed>}
 * @phpstan-type Data array{score: int, breakdown: array<string, Factor>, matchedSkills: list<string>, missingSkills: list<string>, version: string}
 */
final readonly class FitScore
{
    /**
     * @param  array<string, Factor>  $breakdown
     * @param  list<string>  $matchedSkills
     * @param  list<string>  $missingSkills
     */
    public function __construct(
        public int $score,
        public array $breakdown,
        public array $matchedSkills,
        public array $missingSkills,
        public string $version,
    ) {}

    /**
     * @return Data
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'breakdown' => $this->breakdown,
            'matchedSkills' => $this->matchedSkills,
            'missingSkills' => $this->missingSkills,
            'version' => $this->version,
        ];
    }
}
