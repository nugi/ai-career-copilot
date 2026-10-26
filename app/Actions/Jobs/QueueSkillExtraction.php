<?php

namespace App\Actions\Jobs;

use App\Ai\DailyBudget;
use App\Jobs\ExtractJobSkills;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Queue one skill extraction per distinct posting text among the pending postings of a source (ADR 0024).
 *
 * Nothing is queued while the global daily budget is spent, because the postings stay pending and the next run picks
 * them up. Postings that share a text queue one job: the lowest ID is queued and the rest copy its result.
 */
class QueueSkillExtraction
{
    public function __construct(private DailyBudget $budget) {}

    /**
     * Queue the pending postings, filtered by source when one is given, and return how many jobs were dispatched.
     */
    public function handle(?string $source = null, ?int $limit = null): int
    {
        if ($this->budget->isGloballyExceeded()) {
            Log::info('jobs.skills.skipped_budget', ['source' => $source]);

            return 0;
        }

        /** @var list<int> $postingIds */
        $postingIds = JobPosting::query()
            ->needsSkills()
            ->when($source !== null, fn (Builder $query): Builder => $query->where('source', $source))
            ->selectRaw('min(id) as id')
            ->groupBy('text_hash')
            ->orderBy('id')
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit($limit))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $postings = JobPosting::query()->whereKey($postingIds)->orderBy('id')->get();

        foreach ($postings as $posting) {
            ExtractJobSkills::dispatch($posting);
        }

        return $postings->count();
    }
}
