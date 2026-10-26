<?php

namespace App\Console\Commands;

use App\Actions\Jobs\QueueSkillExtraction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('skills:extract {--source= : Only this source} {--limit=200 : The most postings to queue}')]
#[Description('Queue the pending postings for skill extraction.')]
class ExtractSkillsCommand extends Command
{
    /**
     * Queue the pending postings, one job per distinct text, and print how many were queued.
     */
    public function handle(QueueSkillExtraction $queueSkillExtraction): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('The --limit option must be 1 or more.');

            return self::FAILURE;
        }

        /** @var string|null $source */
        $source = $this->option('source');

        $queued = $queueSkillExtraction->handle($source, $limit);

        $this->info("Queued {$queued} postings for skill extraction.");

        return self::SUCCESS;
    }
}
