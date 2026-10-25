<?php

namespace App\Console\Commands;

use App\Jobs\IngestJobSource;
use App\JobSources\JobSourceRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Queues the daily ingestion run for every job source, or for one named source.
 */
#[Signature('jobs:ingest {source? : The source key; queues every registered source when omitted}')]
#[Description('Queue one ingestion run for each job source')]
class IngestJobSourcesCommand extends Command
{
    /**
     * Queue one unique run per chosen source and print its key.
     */
    public function handle(JobSourceRegistry $registry): int
    {
        $key = $this->argument('source');

        if ($key !== null && ! array_key_exists($key, $registry->all())) {
            $this->error("Unknown job source [{$key}].");

            return self::FAILURE;
        }

        $keys = $key === null ? array_keys($registry->all()) : [$key];

        foreach ($keys as $sourceKey) {
            IngestJobSource::dispatch($sourceKey);

            $this->info("Queued ingestion for [{$sourceKey}].");
        }

        return self::SUCCESS;
    }
}
