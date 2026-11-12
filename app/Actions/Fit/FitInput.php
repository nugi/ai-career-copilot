<?php

namespace App\Actions\Fit;

/**
 * @phpstan-type Data array{requiredSkills: list<string>, niceSkills: list<string>, userSkills: list<string>, yearsOfExperience: float|null, yearsRequired: int|null, seniority: string|null, salaryFloor: int|null, salaryMax: int|null, arrangementMatches: bool, eligibility: string}
 */
final readonly class FitInput
{
    /**
     * @param  list<string>  $requiredSkills
     * @param  list<string>  $niceSkills
     * @param  list<string>  $userSkills
     */
    public function __construct(
        public array $requiredSkills,
        public array $niceSkills,
        public array $userSkills,
        public ?float $yearsOfExperience,
        public ?int $yearsRequired,
        public ?string $seniority,
        public ?int $salaryFloor,
        public ?int $salaryMax,
        public bool $arrangementMatches,
        public string $eligibility,
    ) {}

    /**
     * @return Data
     */
    public function toArray(): array
    {
        return [
            'requiredSkills' => $this->requiredSkills,
            'niceSkills' => $this->niceSkills,
            'userSkills' => $this->userSkills,
            'yearsOfExperience' => $this->yearsOfExperience,
            'yearsRequired' => $this->yearsRequired,
            'seniority' => $this->seniority,
            'salaryFloor' => $this->salaryFloor,
            'salaryMax' => $this->salaryMax,
            'arrangementMatches' => $this->arrangementMatches,
            'eligibility' => $this->eligibility,
        ];
    }
}
