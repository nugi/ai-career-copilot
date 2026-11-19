<?php

namespace App\Actions\Skills;

/**
 * One skill that the user's top matches ask for and the user does not have.
 */
final readonly class SkillGap
{
    public function __construct(
        public string $skill,
        public float $weight,
        public int $requiredJobs,
        public int $niceJobs,
        public int $jobs,
        public int $of,
    ) {}

    /**
     * @return array{skill: string, weight: float, required_jobs: int, nice_jobs: int, jobs: int, of: int}
     */
    public function toArray(): array
    {
        return [
            'skill' => $this->skill,
            'weight' => $this->weight,
            'required_jobs' => $this->requiredJobs,
            'nice_jobs' => $this->niceJobs,
            'jobs' => $this->jobs,
            'of' => $this->of,
        ];
    }
}
