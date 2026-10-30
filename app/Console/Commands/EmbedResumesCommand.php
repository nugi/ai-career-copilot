<?php

namespace App\Console\Commands;

use App\Actions\Resume\ChunkResume;
use App\Actions\Resume\EmbedResumeChunks;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuild the chunks of parsed resumes and embed the pending ones, for the hourly run and for backfills.
 */
#[Signature('resumes:embed {--resume=* : The IDs of the resumes to embed}')]
#[Description('Chunk the parsed resumes and embed the pending chunks.')]
class EmbedResumesCommand extends Command
{
    /**
     * Rebuild saved parses and fill pending vectors in resume order so later resumes can reuse them.
     */
    public function handle(ChunkResume $chunkResume, EmbedResumeChunks $embed): int
    {
        $query = Resume::query()->whereNotNull('parsed_at')->orderBy('id');
        $ids = $this->option('resume');

        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $counts = ['embedded' => 0, 'copied' => 0, 'skipped' => 0];
        $resumes = 0;
        $failed = 0;

        foreach ($query->lazyById() as $resume) {
            try {
                $locked = DB::transaction(function () use ($resume, $chunkResume): ?Resume {
                    User::query()->whereKey($resume->user_id)->lockForUpdate()->sole();
                    $locked = Resume::query()->whereKey($resume->getKey())->lockForUpdate()->first();

                    if ($locked === null || $locked->parsed_at === null) {
                        return null;
                    }

                    $chunkResume->sync($locked);

                    return $locked;
                });

                if ($locked === null) {
                    continue;
                }

                $result = $embed->handle($locked);

                foreach ($counts as $key => $count) {
                    $counts[$key] += $result[$key];
                }

                $resumes++;
            } catch (Throwable $exception) {
                Log::error('resumes.embeddings.command_failed', [
                    'resume_id' => $resume->id,
                    'exception' => $exception::class,
                ]);
                $failed++;
            }
        }

        $this->info("Embedded {$counts['embedded']}, copied {$counts['copied']}, skipped {$counts['skipped']} chunks of {$resumes} resumes.");

        if ($failed > 0) {
            $this->error("{$failed} resumes failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
