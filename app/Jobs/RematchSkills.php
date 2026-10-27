<?php

namespace App\Jobs;

use App\Actions\Skills\RematchUnknownSkills;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-resolve stored names after catalogue changes, sharing one queued job until processing begins.
 */
class RematchSkills implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Match unknown names without calling an AI provider.
     */
    public function handle(RematchUnknownSkills $rematch): void
    {
        $rematch->handle();
    }
}
