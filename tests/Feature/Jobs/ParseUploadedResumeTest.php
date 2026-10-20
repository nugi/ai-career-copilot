<?php

use App\Ai\Agents\ResumeParser;
use App\Jobs\ParseUploadedResume;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Support\Facades\Log;

test('the job parses the resume and saves the parsed data', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $resume = Resume::factory()->extracted()->primary()->for(User::factory()->onboarded())->create();

    ParseUploadedResume::dispatchSync($resume);

    $resume->refresh();
    expect($resume->parsed_data['skills'])->toBe(['Laravel', 'PHP', 'PostgreSQL'])
        ->and($resume->parsed_at)->not->toBeNull();
    ResumeParser::assertPromptedTimes(1);
});

test('the job records a provider failure on the resume and logs it without the exception message', function () {
    Log::spy();
    $resume = Resume::factory()->extracted()->primary()->for(User::factory()->onboarded())->create();

    (new ParseUploadedResume($resume))->failed(new RuntimeException('The request body with the resume text.'));

    $resume->refresh();
    expect($resume->parse_failed_at)->not->toBeNull()
        ->and($resume->parse_errors)->toBe([['path' => '*', 'rule' => 'ProviderUnavailable']]);
    Log::shouldHaveReceived('error')->with('ai.resume.parse_job_failed', [
        'resume_id' => $resume->id,
        'exception' => RuntimeException::class,
    ])->once();
});

test('the unique id is the resume id', function () {
    $resume = Resume::factory()->extracted()->for(User::factory()->onboarded())->create();

    expect((new ParseUploadedResume($resume))->uniqueId())->toBe((string) $resume->id);
});
