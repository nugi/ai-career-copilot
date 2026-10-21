<?php

namespace App\Ai\Contracts;

use App\Enums\AiFeature;
use App\Models\User;

/**
 * Marks an agent whose calls are recorded in ai_usages.
 *
 * An agent without this contract is still answered, but its calls write no usage row, so a new agent cannot
 * add cost that no report shows.
 */
interface TracksUsage
{
    /**
     * Get the task that the agent does, which decides its model tier and its usage feature.
     */
    public function task(): AiFeature;

    /**
     * Get the user whose request caused the call, or null when no user is known.
     */
    public function usageUser(): ?User;
}
