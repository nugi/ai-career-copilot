<?php

namespace App\Console\Commands;

use App\Actions\Jobs\EmbedPostings;
use App\Actions\Jobs\QueueEmbeddings;
use App\Ai\DailyBudget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Queue routine embedding work or backfill it in the foreground so an operator can follow progress.
 */
#[Signature('jobs:embed {--backfill : Embed now in this process, with a progress bar} {--limit= : The most postings to embed}')]
#[Description('Embed the settled live job postings.')]
class EmbedPostingsCommand extends Command
{
    /**
     * Respect the batch budget in either mode and report progress so backfills remain observable.
     */
    public function handle(QueueEmbeddings $queue, EmbedPostings $embed, DailyBudget $budget): int
    {
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        if ($limit !== null && $limit < 1) {
            $this->error('The --limit option must be 1 or more.');

            return self::FAILURE;
        }

        if (! $this->option('backfill')) {
            $queued = $queue->handle($limit);
            $this->info("Queued {$queued} postings for embedding.");

            return self::SUCCESS;
        }

        if ($budget->isBatchExceeded()) {
            $this->error('The batch AI budget is spent.');

            return self::FAILURE;
        }

        $postingIds = $queue->pendingIds($limit);

        if ($postingIds === []) {
            $this->info('Nothing to embed.');

            return self::SUCCESS;
        }

        $counts = ['embedded' => 0, 'copied' => 0, 'skipped' => 0];
        $progress = $this->output->createProgressBar(count($postingIds));
        $progress->start();

        foreach (array_chunk($postingIds, config('ai-routing.embeddings.batch_size')) as $batch) {
            $result = $embed->handle($batch);

            foreach ($counts as $key => $count) {
                $counts[$key] += $result[$key];
            }

            $progress->advance(count($batch));
        }

        $progress->finish();
        $this->newLine();
        $this->info("Embedded {$counts['embedded']}, copied {$counts['copied']}, skipped {$counts['skipped']}.");

        return self::SUCCESS;
    }
}
