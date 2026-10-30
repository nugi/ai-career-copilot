<?php

use App\Ai\Agents\ResumeParser;
use App\Enums\SkillSource;
use App\Jobs\EmbedResumeChunks;
use App\Jobs\ParseUploadedResume;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

beforeEach(function () {
    Queue::fake([EmbedResumeChunks::class]);
});

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

test('the unique id is the user id and the content hash', function () {
    $resume = Resume::factory()->extracted()->for(User::factory()->onboarded())->create();

    expect((new ParseUploadedResume($resume))->uniqueId())
        ->toBe("{$resume->user_id}:{$resume->content_hash}");
});

test('the unique id is the resume id when the resume has no content', function () {
    $resume = Resume::factory()->for(User::factory()->onboarded())->create();

    expect((new ParseUploadedResume($resume))->uniqueId())->toBe((string) $resume->id);
});

test('a duplicate resume with a usable parse is copied without a provider call', function () {
    ResumeParser::fake()->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $duplicate = Resume::factory()->parsed()->for($user)->create();
    $resume = Resume::factory()->extracted()->for($user)->create();

    ParseUploadedResume::dispatchSync($resume);

    $resume->refresh();
    expect($resume->parsed_data)->toBe($duplicate->refresh()->parsed_data)
        ->and($resume->prompt_version)->toBe(ResumeParser::PROMPT_VERSION)
        ->and($resume->parsed_at)->not->toBeNull()
        ->and($resume->parse_failed_at)->toBeNull();
    ResumeParser::assertNeverPrompted();
});

test('a duplicate with a parse from an old prompt version is not copied', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    Resume::factory()->parsed()->for($user)->create(['prompt_version' => 'v0']);
    $resume = Resume::factory()->extracted()->for($user)->create();

    ParseUploadedResume::dispatchSync($resume);

    expect($resume->refresh()->parsed_data['skills'])->toBe(['Laravel', 'PHP', 'PostgreSQL']);
    ResumeParser::assertPromptedTimes(1);
});

test('a successful parse is copied to a pending duplicate', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->for($user)->create();
    $duplicate = Resume::factory()->extracted()->for($user)->create();

    ParseUploadedResume::dispatchSync($resume);

    $duplicate->refresh();
    expect($duplicate->parsed_data)->toBe($resume->refresh()->parsed_data)
        ->and($duplicate->parsed_at)->not->toBeNull()
        ->and($duplicate->parse_failed_at)->toBeNull();
    ResumeParser::assertPromptedTimes(1);
});

test('a failed parse is copied to a pending duplicate', function () {
    $invalid = parsedResume(['experiences' => [[
        'title' => 'Backend Engineer',
        'company' => 'Acme Corp',
        'start' => '2021-06',
        'end' => '2020-12',
        'bullets' => [],
    ]]]);
    ResumeParser::fake([$invalid, $invalid])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->for($user)->create();
    $duplicate = Resume::factory()->extracted()->for($user)->create();

    ParseUploadedResume::dispatchSync($resume);

    $resume->refresh();
    $duplicate->refresh();
    expect($resume->parse_failed_at)->not->toBeNull()
        ->and($duplicate->parse_failed_at)->not->toBeNull()
        ->and($duplicate->parse_errors)->toBe($resume->parse_errors)
        ->and($duplicate->parsed_at)->toBeNull();
    ResumeParser::assertPromptedTimes(2);
});

test('a parsed resume and its pending duplicate queue embeddings and have saved chunks', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->for($user)->create();
    $duplicate = Resume::factory()->extracted()->for($user)->create();

    ParseUploadedResume::dispatchSync($resume);

    Queue::assertPushed(EmbedResumeChunks::class, fn (EmbedResumeChunks $job): bool => $job->resume->is($resume));
    Queue::assertPushed(EmbedResumeChunks::class, fn (EmbedResumeChunks $job): bool => $job->resume->is($duplicate));
    Queue::assertPushed(EmbedResumeChunks::class, 2);
    $this->assertDatabaseHas('resume_chunks', ['resume_id' => $resume->id, 'position' => 0, 'section' => 'summary']);
    expect($resume->chunks()->count())->toBe(4)
        ->and($duplicate->chunks()->count())->toBe(4);
});

test('a provider failure marks a pending duplicate as failed', function () {
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->for($user)->create();
    $duplicate = Resume::factory()->extracted()->for($user)->create();

    (new ParseUploadedResume($resume))->failed(new RuntimeException('The request body with the resume text.'));

    $duplicate->refresh();
    expect($duplicate->parse_failed_at)->not->toBeNull()
        ->and($duplicate->parse_errors)->toBe([['path' => '*', 'rule' => 'ProviderUnavailable']])
        ->and($duplicate->parsed_at)->toBeNull();
});

test('a resume of another user with the same content is neither copied nor changed', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $other = Resume::factory()->parsed()->for(User::factory()->onboarded())->create();
    $otherParsedData = $other->refresh()->parsed_data;
    $resume = Resume::factory()->extracted()->for(User::factory()->onboarded())->create();

    ParseUploadedResume::dispatchSync($resume);

    expect($other->refresh()->parsed_data)->toBe($otherParsedData)
        ->and($resume->refresh()->parsed_data['skills'])->toBe(['Laravel', 'PHP', 'PostgreSQL']);
    ResumeParser::assertPromptedTimes(1);
});

test('a copied parse on a primary resume syncs the resume skills of the user', function () {
    ResumeParser::fake()->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    Resume::factory()->parsed()->for($user)->create();
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    ParseUploadedResume::dispatchSync($resume);

    expect($user->skills()->wherePivot('source', SkillSource::Resume)->pluck('name')->sort()->values()->all())
        ->toBe(['Docker', 'Laravel', 'PHP', 'PostgreSQL', 'Redis']);
});

test('a provider outage fails the job and keeps the earlier parse', function () {
    ResumeParser::fake(fn () => throw ProviderOverloadedException::forProvider('openai'));
    $resume = Resume::factory()->parsed()->primary()->for(User::factory()->onboarded())->create();
    $parsedData = $resume->fresh()->parsed_data;

    expect(fn () => ParseUploadedResume::dispatchSync($resume))->toThrow(ProviderOverloadedException::class);

    $resume->refresh();
    expect($resume->parse_errors)->toBe([['path' => '*', 'rule' => 'ProviderUnavailable']])
        ->and($resume->parsed_data)->toBe($parsedData);
});
