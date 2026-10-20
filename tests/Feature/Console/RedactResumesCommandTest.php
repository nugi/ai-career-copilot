<?php

use App\Jobs\ParseUploadedResume;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    Queue::fake([ParseUploadedResume::class]);
});

/**
 * Store the PDF fixture at the file path of the resume on the fake disk.
 */
function storeResumeFixture(Resume $resume, string $fixture = 'text-resume.pdf'): void
{
    Storage::put($resume->file_path, file_get_contents(base_path("tests/Fixtures/resumes/{$fixture}")));
}

/**
 * The rows of the summary table. Each count that is not given is 0.
 *
 * @param  array<string, int>  $counts
 * @return list<array{string, int}>
 */
function redactSummaryRows(array $counts): array
{
    return collect(['Checked', 'Changed', 'Unchanged', 'Missing file', 'Failed', 'Parses queued'])
        ->map(fn (string $item): array => [$item, $counts[$item] ?? 0])
        ->all();
}

test('a resume with unredacted content gets the redacted content and a new parse', function () {
    $user = User::factory()->create(['name' => 'Rina Wijaya']);
    $resume = Resume::factory()->for($user)->create(['content' => 'Old text with rina.wijaya@example.com and Rina Wijaya']);
    storeResumeFixture($resume);

    $this->artisan('resume:redact')
        ->expectsTable(['Item', 'Value'], redactSummaryRows(['Checked' => 1, 'Changed' => 1, 'Parses queued' => 1]))
        ->assertExitCode(0);

    $content = $resume->fresh()->content;

    expect($content)->toContain('[EMAIL]', '[NAME]')
        ->and($content)->not->toContain('rina.wijaya@example.com')
        ->and($content)->not->toContain('Rina');
    Queue::assertPushed(ParseUploadedResume::class, fn (ParseUploadedResume $job): bool => $job->resume->is($resume));
});

test('the without-parse option saves the redacted content and queues no parse', function () {
    $user = User::factory()->create(['name' => 'Rina Wijaya']);
    $resume = Resume::factory()->for($user)->create(['content' => 'Old text with rina.wijaya@example.com']);
    storeResumeFixture($resume);

    $this->artisan('resume:redact', ['--without-parse' => true])
        ->expectsTable(['Item', 'Value'], redactSummaryRows(['Checked' => 1, 'Changed' => 1]))
        ->assertExitCode(0);

    expect($resume->fresh()->content)->toContain('[EMAIL]')
        ->and($resume->fresh()->content)->not->toContain('rina.wijaya@example.com');
    Queue::assertNothingPushed();
});

test('a resume whose content is already redacted is unchanged and gets no parse', function () {
    $user = User::factory()->create(['name' => 'Rina Wijaya']);
    $resume = Resume::factory()->for($user)->create(['content' => 'Old text with rina.wijaya@example.com']);
    storeResumeFixture($resume);

    $this->artisan('resume:redact', ['--without-parse' => true])->assertExitCode(0);
    $contentAfterFirstRun = $resume->fresh()->content;

    $this->artisan('resume:redact')
        ->expectsTable(['Item', 'Value'], redactSummaryRows(['Checked' => 1, 'Unchanged' => 1]))
        ->assertExitCode(0);

    expect($resume->fresh()->content)->toBe($contentAfterFirstRun);
    Queue::assertNothingPushed();
});

test('a resume whose file is missing is counted as missing and keeps its content', function () {
    $user = User::factory()->create();
    $resume = Resume::factory()->for($user)->create(['content' => 'Old text']);

    $this->artisan('resume:redact')
        ->expectsTable(['Item', 'Value'], redactSummaryRows(['Checked' => 1, 'Missing file' => 1]))
        ->assertExitCode(0);

    expect($resume->fresh()->content)->toBe('Old text');
    Queue::assertNothingPushed();
});

test('a resume whose file has no readable text is counted as failed and keeps its content', function () {
    $user = User::factory()->create();
    $resume = Resume::factory()->for($user)->create(['content' => 'Old text']);
    storeResumeFixture($resume, 'scanned-resume.pdf');

    $this->artisan('resume:redact')
        ->expectsTable(['Item', 'Value'], redactSummaryRows(['Checked' => 1, 'Failed' => 1]))
        ->assertExitCode(0);

    expect($resume->fresh()->content)->toBe('Old text');
    Queue::assertNothingPushed();
});

test('a resume ID limits the command to that resume', function () {
    $user = User::factory()->create(['name' => 'Rina Wijaya']);
    $resume = Resume::factory()->for($user)->create(['content' => 'Old text']);
    $other = Resume::factory()->for($user)->create(['content' => 'Other text']);
    storeResumeFixture($resume);
    storeResumeFixture($other);

    $this->artisan('resume:redact', ['resume' => $resume->id])
        ->expectsTable(['Item', 'Value'], redactSummaryRows(['Checked' => 1, 'Changed' => 1, 'Parses queued' => 1]))
        ->assertExitCode(0);

    expect($other->fresh()->content)->toBe('Other text');
});

test('an unknown resume ID exits with 1', function () {
    $this->artisan('resume:redact', ['resume' => 999999])
        ->expectsOutputToContain('The resume was not found.')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});
