<?php

namespace App\Console\Commands;

use App\Actions\Resume\ParseResume;
use App\Models\Resume;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('resume:parse {resume : The ID of the resume} {--show : Print the parsed data as JSON}')]
#[Description('Parse a resume into structured data now, without the queue.')]
class ParseResumeCommand extends Command
{
    /**
     * Parse the resume synchronously and print a summary, or the parsed data with --show.
     */
    public function handle(ParseResume $parseResume): int
    {
        $resume = Resume::find($this->argument('resume'));

        if ($resume === null) {
            $this->error('The resume was not found.');

            return self::FAILURE;
        }

        if ($resume->content === null || $resume->content === '') {
            $this->error('The resume has no text to parse.');

            return self::FAILURE;
        }

        $resume = $parseResume->handle($resume);

        if ($resume->parse_failed_at !== null) {
            $this->error('The parse failed.');
            $this->table(
                ['Path', 'Rule'],
                array_map(fn (array $error): array => [$error['path'], $error['rule']], $resume->parse_errors),
            );

            return self::FAILURE;
        }

        $parsed = $resume->parsed_data;

        $this->info("Parsed resume {$resume->getKey()}.");
        $this->table(['Item', 'Value'], [
            ['Skills', count($parsed['skills'])],
            ['Experiences', count($parsed['experiences'])],
            ['Education entries', count($parsed['education'])],
            ['Years of experience', $parsed['years_of_experience'] ?? 'none'],
            ['Model years of experience', $parsed['checks']['model_years_of_experience'] ?? 'none'],
            ['Years mismatch', $parsed['checks']['years_mismatch'] ? 'yes' : 'no'],
            ['Ungrounded skills', count($parsed['checks']['ungrounded_skills'])],
        ]);

        if ($this->option('show')) {
            $this->line(json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
