<?php

namespace App\Console\Commands;

use App\Actions\Resume\ExtractResumeText;
use App\Ai\PiiRedactor;
use App\Jobs\ParseUploadedResume;
use App\Models\Resume;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

#[Signature('resume:redact {resume? : The ID of one resume. All resumes when omitted} {--without-parse : Do not queue a new parse for a changed resume}')]
#[Description('Extract and redact stored resumes again with the current redaction rules.')]
class RedactResumesCommand extends Command
{
    /**
     * Extract and redact the stored resumes, save the changed text, and queue a new parse for each changed resume.
     *
     * The command prints IDs and counts only. It never prints the resume text or the names.
     */
    public function handle(ExtractResumeText $extractResumeText, PiiRedactor $redactor): int
    {
        $resumeId = $this->argument('resume');

        if ($resumeId !== null && ! Resume::query()->whereKey($resumeId)->exists()) {
            $this->error('The resume was not found.');

            return self::FAILURE;
        }

        $counts = [
            'Checked' => 0,
            'Changed' => 0,
            'Unchanged' => 0,
            'Missing file' => 0,
            'Failed' => 0,
            'Parses queued' => 0,
        ];

        Resume::query()
            ->with('user')
            ->when($resumeId !== null, fn (Builder $query): Builder => $query->whereKey($resumeId))
            ->chunkById(100, function (Collection $resumes) use ($extractResumeText, $redactor, &$counts): void {
                foreach ($resumes as $resume) {
                    $counts['Checked']++;

                    if (! Storage::exists($resume->file_path)) {
                        $counts['Missing file']++;
                        $this->warn("Resume {$resume->getKey()} is skipped. Its file does not exist.");

                        continue;
                    }

                    try {
                        $text = $extractResumeText->handle(Storage::path($resume->file_path));
                    } catch (ValidationException) {
                        $text = '';
                    }

                    if ($text === '') {
                        $counts['Failed']++;
                        $this->warn("Resume {$resume->getKey()} failed. Its file has no readable text.");

                        continue;
                    }

                    $content = $redactor->redact($text, $resume->user->name);

                    if ($content === $resume->content) {
                        $counts['Unchanged']++;

                        continue;
                    }

                    $resume->content = $content;
                    $resume->save();
                    $counts['Changed']++;

                    if (! $this->option('without-parse')) {
                        ParseUploadedResume::dispatch($resume);
                        $counts['Parses queued']++;
                    }
                }
            });

        $this->table(
            ['Item', 'Value'],
            collect($counts)->map(fn (int $count, string $item): array => [$item, $count])->values()->all(),
        );

        return self::SUCCESS;
    }
}
