<?php

use App\Models\Resume;

/**
 * A parsed resume whose checks are the given values. The other parse columns come from the factory.
 *
 * @param  array<string, mixed>  $checks
 */
function resumeWithChecks(array $checks, string $promptVersion = 'v1'): Resume
{
    return Resume::factory()->parsed()->create([
        'parsed_data' => ['checks' => $checks],
        'prompt_version' => $promptVersion,
    ]);
}

test('the stats show the share of each check that flagged a problem, for each prompt version', function () {
    resumeWithChecks([
        'model_years_of_experience' => 12,
        'years_mismatch' => true,
        'ungrounded_skills' => ['Go', 'Kubernetes'],
        'ungrounded_bullets' => [],
    ]);
    resumeWithChecks([
        'model_years_of_experience' => 3,
        'years_mismatch' => false,
        'ungrounded_skills' => [],
        'ungrounded_bullets' => [],
    ]);

    $this->artisan('resume:parse-stats')
        ->expectsTable(
            ['Prompt version', 'Parsed', 'Years mismatch', 'Ungrounded skills', 'Average ungrounded skills', 'Ungrounded bullets'],
            [['v1', 2, '50.0%', '50.0%', '1.0', '0.0%']],
        )
        ->expectsOutputToContain('Resumes whose latest parse failed: 0.')
        ->assertExitCode(0);
});

test('each prompt version has its own row', function () {
    resumeWithChecks([
        'model_years_of_experience' => 3,
        'years_mismatch' => false,
        'ungrounded_skills' => [],
        'ungrounded_bullets' => [],
    ], 'v1');
    resumeWithChecks([
        'model_years_of_experience' => 12,
        'years_mismatch' => true,
        'ungrounded_skills' => [],
        'ungrounded_bullets' => ['experiences.0.bullets.0'],
    ], 'v2');

    $this->artisan('resume:parse-stats')
        ->expectsTable(
            ['Prompt version', 'Parsed', 'Years mismatch', 'Ungrounded skills', 'Average ungrounded skills', 'Ungrounded bullets'],
            [
                ['v1', 1, '0.0%', '0.0%', '0.0', '0.0%'],
                ['v2', 1, '100.0%', '0.0%', '0.0', '100.0%'],
            ],
        )
        ->assertExitCode(0);
});

test('a resume parsed before the bullet check counts as having no ungrounded bullets', function () {
    Resume::factory()->parsed()->create([
        'parsed_data' => ['checks' => [
            'model_years_of_experience' => 3,
            'years_mismatch' => false,
            'ungrounded_skills' => [],
        ]],
    ]);

    $this->artisan('resume:parse-stats')
        ->expectsTable(
            ['Prompt version', 'Parsed', 'Years mismatch', 'Ungrounded skills', 'Average ungrounded skills', 'Ungrounded bullets'],
            [['v1', 1, '0.0%', '0.0%', '0.0', '0.0%']],
        )
        ->assertExitCode(0);
});

test('a resume whose latest parse failed is counted in the failed line', function () {
    resumeWithChecks([
        'model_years_of_experience' => 3,
        'years_mismatch' => false,
        'ungrounded_skills' => [],
        'ungrounded_bullets' => [],
    ]);
    Resume::factory()->parsed()->create([
        'parsed_at' => now()->subDay(),
        'parse_failed_at' => now(),
    ]);
    Resume::factory()->extracted()->create([
        'parsed_at' => null,
        'parse_failed_at' => now(),
    ]);
    Resume::factory()->parsed()->create([
        'parsed_at' => now(),
        'parse_failed_at' => now()->subDays(2),
    ]);

    $this->artisan('resume:parse-stats')
        ->expectsOutputToContain('Resumes whose latest parse failed: 2.')
        ->assertExitCode(0);
});

test('no parsed resumes print the message and exit with 0', function () {
    Resume::factory()->extracted()->create();

    $this->artisan('resume:parse-stats')
        ->expectsOutputToContain('No resumes have been parsed yet.')
        ->assertExitCode(0);
});

test('failed parses are counted even when no resume has parsed', function () {
    Resume::factory()->extracted()->create(['parse_failed_at' => now()]);

    $this->artisan('resume:parse-stats')
        ->expectsOutputToContain('No resumes have been parsed yet.')
        ->expectsOutputToContain('Resumes whose latest parse failed: 1.')
        ->assertExitCode(0);
});
