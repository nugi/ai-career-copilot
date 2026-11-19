<?php

namespace App\Actions\Skills;

use App\Models\LearningPlan;
use Carbon\CarbonImmutable;

/**
 * A stored learning plan, or the reason a new plan could not be made.
 */
final readonly class LearningPlanResult
{
    /**
     * @param  'ready'|'too_soon'|'no_gaps'|'budget'|'busy'|'failed'  $status
     */
    public function __construct(
        public string $status,
        public ?LearningPlan $plan = null,
        public ?CarbonImmutable $availableAt = null,
    ) {}
}
