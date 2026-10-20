<?php

use App\Actions\Resume\ParseResume;
use App\Ai\Agents\ResumeParser;
use App\Enums\SkillProficiency;
use App\Enums\SkillSource;
use App\Models\Resume;
use App\Models\Skill;
use App\Models\SkillAlias;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));
});

/**
 * An experience whose end is before its start.
 *
 * @return list<array<string, mixed>>
 */
function experienceEndingBeforeItStarts(): array
{
    return [[
        'title' => 'Backend Engineer',
        'company' => 'Acme Corp',
        'start' => '2021-06',
        'end' => '2020-12',
        'bullets' => [],
    ]];
}

test('a valid output on the first attempt is saved', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    $resume->refresh();
    expect($resume->parsed_data['summary'])->toBe('Backend engineer with five years of experience.')
        ->and($resume->parsed_data['skills'])->toBe(['Laravel', 'PHP', 'PostgreSQL'])
        ->and($resume->prompt_version)->toBe('v1')
        ->and($resume->parsed_at)->not->toBeNull()
        ->and($resume->parse_failed_at)->toBeNull();
    ResumeParser::assertPromptedTimes(1);
});

test('keys outside the schema are not saved', function () {
    $output = parsedResume();
    $output['date_of_birth'] = '1990-01-01';
    $output['experiences'][0]['salary'] = '20000000';
    ResumeParser::fake([$output])->preventStrayPrompts();
    $resume = Resume::factory()->extracted()->primary()->for(User::factory()->onboarded())->create();

    app(ParseResume::class)->handle($resume);

    expect($resume->refresh()->parsed_data)->not->toHaveKey('date_of_birth')
        ->and($resume->parsed_data['experiences'][0])->toHaveKeys(['title', 'company', 'start', 'end', 'bullets'])
        ->not->toHaveKey('salary');
});

test('an invalid output is retried with its errors, and the second output is saved', function () {
    ResumeParser::fake([
        parsedResume(['experiences' => experienceEndingBeforeItStarts()]),
        parsedResume(['summary' => 'Corrected summary.']),
    ])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    expect($resume->refresh()->parsed_data['summary'])->toBe('Corrected summary.');
    ResumeParser::assertPromptedTimes(2);
    ResumeParser::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains("<validation_errors>\n- experiences.0.end: The end date is before the start date.\n</validation_errors>"));
});

test('two invalid outputs record the failure and change nothing else', function () {
    ResumeParser::fake([
        parsedResume(['experiences' => experienceEndingBeforeItStarts()]),
        parsedResume(['experiences' => experienceEndingBeforeItStarts()]),
    ])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $user->skills()->attach(Skill::factory()->create(['name' => 'Laravel']), ['proficiency' => SkillProficiency::Expert, 'source' => SkillSource::Profile]);
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    $resume->refresh();
    expect($resume->parsed_data)->toBeNull()
        ->and($resume->parsed_at)->toBeNull()
        ->and($resume->parse_failed_at)->not->toBeNull()
        ->and($resume->parse_errors)->toBe([['path' => 'experiences.0.end', 'rule' => 'After']])
        ->and($user->skills()->count())->toBe(1);
    ResumeParser::assertPromptedTimes(2);
});

test('a failed re-parse keeps the data of the last successful parse', function () {
    ResumeParser::fake([
        parsedResume(['experiences' => experienceEndingBeforeItStarts()]),
        parsedResume(['experiences' => experienceEndingBeforeItStarts()]),
    ])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->parsed()->primary()->for($user)->create();
    $parsedData = $resume->fresh()->parsed_data;
    $parsedAt = $resume->fresh()->parsed_at->toDateTimeString();

    app(ParseResume::class)->handle($resume);

    $resume->refresh();
    expect($resume->parsed_data)->toBe($parsedData)
        ->and($resume->parsed_at->toDateTimeString())->toBe($parsedAt)
        ->and($resume->parse_failed_at)->not->toBeNull();
});

test('an empty output is invalid and causes a retry', function () {
    ResumeParser::fake([[], parsedResume()])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    expect($resume->refresh()->parsed_at)->not->toBeNull()
        ->and($resume->parse_failed_at)->toBeNull();
    ResumeParser::assertPromptedTimes(2);
});

test('an alias resolves to its canonical skill, saved as a resume skill', function () {
    $postgres = Skill::factory()->create(['name' => 'PostgreSQL']);
    SkillAlias::factory()->for($postgres)->create(['alias' => 'postgres']);
    ResumeParser::fake([parsedResume(['skills' => ['postgres']])])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    $skill = $user->skills()->sole();
    expect($skill->id)->toBe($postgres->id)
        ->and($skill->pivot->source)->toBe(SkillSource::Resume)
        ->and($skill->pivot->proficiency)->toBeNull()
        ->and(Skill::query()->count())->toBe(1)
        ->and($resume->refresh()->parsed_data['skills'])->toBe(['PostgreSQL']);
});

test('a profile skill keeps its source and proficiency when the resume also names it', function () {
    ResumeParser::fake([parsedResume(['skills' => ['Laravel', 'PHP']])])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $user->skills()->attach(Skill::factory()->create(['name' => 'Laravel']), ['proficiency' => SkillProficiency::Expert, 'source' => SkillSource::Profile]);
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    $skills = $user->skills()->get()->keyBy('name');
    expect($skills)->toHaveCount(2)
        ->and($skills['Laravel']->pivot->source)->toBe(SkillSource::Profile)
        ->and($skills['Laravel']->pivot->proficiency)->toBe(SkillProficiency::Expert)
        ->and($skills['PHP']->pivot->source)->toBe(SkillSource::Resume);
});

test('a skill that the resume text does not name is not attached and is listed as ungrounded', function () {
    ResumeParser::fake([parsedResume(['skills' => ['PHP', 'Go', 'Kubernetes']])])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->primary()->for($user)->create(['content' => 'Good communicator. Built PHP APIs for two years.']);

    app(ParseResume::class)->handle($resume);

    expect($resume->refresh()->parsed_data['checks']['ungrounded_skills'])->toBe(['Go', 'Kubernetes'])
        ->and($user->skills()->get()->pluck('name')->all())->toBe(['PHP'])
        ->and(Skill::query()->where('normalized_name', 'go')->exists())->toBeFalse();
});

test('overlapping jobs count once, and a model value that differs flags the parse', function () {
    Log::spy();
    ResumeParser::fake([parsedResume([
        'years_of_experience' => 12,
        'experiences' => [
            ['title' => 'Backend Engineer', 'company' => 'Acme Corp', 'start' => '2018-01', 'end' => '2020-12', 'bullets' => []],
            ['title' => 'Senior Engineer', 'company' => 'Beta Ltd', 'start' => '2020-06', 'end' => '2022-12', 'bullets' => []],
        ],
    ])])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    $resume->refresh();
    expect($resume->yearsOfExperience())->toEqual(5.0)
        ->and($resume->parsed_data)->not->toHaveKey('years_of_experience')
        ->and($resume->parsed_data['checks']['model_years_of_experience'])->toBe(12)
        ->and($resume->parsed_data['checks']['years_mismatch'])->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'ai.resume.parse_flagged'
        && $context['resume_id'] === $resume->id
        && $context['prompt_version'] === 'v1'
        && $context['years_mismatch'] === true
        && $context['ungrounded_skill_count'] === 0);
});

test('a re-parse with fewer skills removes the old resume skills and keeps the profile skills', function () {
    ResumeParser::fake([
        parsedResume(['skills' => ['PHP', 'PostgreSQL']]),
        parsedResume(['skills' => ['PHP']]),
    ])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $user->skills()->attach(Skill::factory()->create(['name' => 'Laravel']), ['proficiency' => SkillProficiency::Expert, 'source' => SkillSource::Profile]);
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    $parsed = app(ParseResume::class)->handle($resume);
    app(ParseResume::class)->handle($parsed);

    expect($user->skills()->get()->pluck('name')->sort()->values()->all())->toBe(['Laravel', 'PHP']);
});

test('the profile version increases when resume skills change and not when they stay the same', function () {
    ResumeParser::fake([
        parsedResume(['skills' => ['PHP']]),
        parsedResume(['skills' => ['PHP']]),
    ])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $version = $user->profile->version;
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    $parsed = app(ParseResume::class)->handle($resume);
    expect($user->profile()->first()->version)->toBe($version + 1);

    app(ParseResume::class)->handle($parsed);
    expect($user->profile()->first()->version)->toBe($version + 1);
});

test('a resume that is not primary saves its data and does not change the skills', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $user->skills()->attach(Skill::factory()->create(['name' => 'Redis']), ['proficiency' => null, 'source' => SkillSource::Resume]);
    $version = $user->profile->version;
    $resume = Resume::factory()->extracted()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    expect($resume->refresh()->parsed_data['skills'])->toBe(['Laravel', 'PHP', 'PostgreSQL'])
        ->and($user->skills()->get()->pluck('name')->all())->toBe(['Redis'])
        ->and($user->profile()->first()->version)->toBe($version);
});

test('a bullet that the resume text does not contain word for word is listed as ungrounded and flags the parse', function () {
    Log::spy();
    ResumeParser::fake([parsedResume([
        'experiences' => [[
            'title' => 'Backend Engineer',
            'company' => 'Acme Corp',
            'start' => '2019-01',
            'end' => '2021-12',
            'bullets' => ['Designed a PostgreSQL schema for 2M orders', 'Cut the API latency by a third'],
        ]],
    ])])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    $resume->refresh();
    expect($resume->parsed_data['checks']['ungrounded_bullets'])->toBe(['experiences.0.bullets.1'])
        ->and($resume->parse_failed_at)->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'ai.resume.parse_flagged'
        && $context['resume_id'] === $resume->id
        && $context['years_mismatch'] === false
        && $context['ungrounded_skill_count'] === 0
        && $context['ungrounded_bullet_count'] === 1);
});

test('a start date in the future is invalid', function () {
    $future = parsedResume(['experiences' => [[
        'title' => 'Backend Engineer',
        'company' => 'Acme Corp',
        'start' => '2026-11',
        'end' => null,
        'bullets' => [],
    ]]]);
    ResumeParser::fake([$future, $future])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    expect($resume->refresh()->parsed_at)->toBeNull()
        ->and($resume->parse_errors)->toBe([['path' => 'experiences.0.start', 'rule' => 'After']]);
});

test('an outage on every provider is thrown and leaves the resume unchanged', function () {
    ResumeParser::fake(fn () => throw ProviderOverloadedException::forProvider('openai'));
    $resume = Resume::factory()->parsed()->primary()->for(User::factory()->onboarded())->create();
    $parsedData = $resume->fresh()->parsed_data;

    expect(fn () => app(ParseResume::class)->handle($resume))->toThrow(ProviderOverloadedException::class);

    $resume->refresh();
    expect($resume->parsed_data)->toBe($parsedData)
        ->and($resume->parse_failed_at)->toBeNull()
        ->and($resume->parse_errors)->toBeNull();
    ResumeParser::assertPromptedTimes(2);
});

test('an outage on the retry is thrown and records no validation failure', function () {
    $outputs = [parsedResume(['experiences' => experienceEndingBeforeItStarts()])];
    ResumeParser::fake(function () use (&$outputs): array {
        return array_shift($outputs) ?? throw ProviderOverloadedException::forProvider('openai');
    });
    $resume = Resume::factory()->extracted()->primary()->for(User::factory()->onboarded())->create();

    expect(fn () => app(ParseResume::class)->handle($resume))->toThrow(ProviderOverloadedException::class);

    $resume->refresh();
    expect($resume->parsed_data)->toBeNull()
        ->and($resume->parse_failed_at)->toBeNull()
        ->and($resume->parse_errors)->toBeNull();
    ResumeParser::assertPromptedTimes(3);
});

test('malformed JSON from the provider is retried and then recorded as a failure', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('{"summary": "Backend engineer'))]);
    $resume = Resume::factory()->extracted()->primary()->for(User::factory()->onboarded())->create();

    app(ParseResume::class)->handle($resume);

    $resume->refresh();
    expect($resume->parsed_at)->toBeNull()
        ->and($resume->parse_failed_at)->not->toBeNull()
        ->and($resume->parse_errors)->toBe([
            ['path' => 'summary', 'rule' => 'Present'],
            ['path' => 'skills', 'rule' => 'Present'],
            ['path' => 'years_of_experience', 'rule' => 'Present'],
            ['path' => 'experiences', 'rule' => 'Present'],
            ['path' => 'education', 'rule' => 'Present'],
        ]);
    Http::assertSentCount(2);
});

test('a resume without text records the failure without calling the provider', function (?string $content) {
    ResumeParser::fake()->preventStrayPrompts();
    $resume = Resume::factory()->for(User::factory()->create())->create(['content' => $content]);

    app(ParseResume::class)->handle($resume);

    $resume->refresh();
    expect($resume->parse_failed_at)->not->toBeNull()
        ->and($resume->parse_errors)->toBe([['path' => 'content', 'rule' => 'Required']])
        ->and($resume->parsed_at)->toBeNull();
    ResumeParser::assertNeverPrompted();
})->with([
    'no text' => [null],
    'an empty string' => [''],
    'only whitespace' => ["  \n\t  "],
]);
