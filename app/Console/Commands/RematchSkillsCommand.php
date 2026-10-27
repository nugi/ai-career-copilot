<?php

namespace App\Console\Commands;

use App\Actions\Skills\RematchUnknownSkills;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('skills:rematch')]
#[Description('Attach the stored unknown skill names that the catalogue now resolves, with no model call.')]
class RematchSkillsCommand extends Command
{
    /**
     * Run resolution against the current catalogue and report the number of changed postings.
     */
    public function handle(RematchUnknownSkills $rematch): int
    {
        $changed = $rematch->handle();
        $this->info("Re-matched {$changed} postings.");

        return self::SUCCESS;
    }
}
