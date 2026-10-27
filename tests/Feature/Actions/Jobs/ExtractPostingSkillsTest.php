<?php

use App\Actions\Jobs\ExtractPostingSkills;
use App\Ai\Agents\JobSkillExtractor;
use App\Enums\SeniorityLevel;
use App\JobSources\Normalization\NormalizedPosting;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\SkillAlias;

/**
 * A live posting whose text hash is the hash of its own title and description, as an import stores it.
 *
 * @param  array<string, mixed>  $attributes
 */
function extractablePosting(string $title, string $description, array $attributes = []): JobPosting
{
    return JobPosting::factory()->create([
        'title' => $title,
        'description' => $description,
        'text_hash' => NormalizedPosting::textHash($title, $description),
        'expires_at' => now()->addDays(20),
        ...$attributes,
    ]);
}

/**
 * An output of the job skill extractor, with the given fields filled.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function extractedJobSkills(array $overrides = []): array
{
    return [
        'required_skills' => [],
        'nice_to_have_skills' => [],
        'seniority' => null,
        'years_required' => null,
        ...$overrides,
    ];
}

test('an extraction attaches catalogue skills and keeps unknown names for review', function () {
    $postgres = Skill::factory()->create(['name' => 'PostgreSQL']);
    SkillAlias::factory()->for($postgres)->create(['alias' => 'postgres']);
    $kubernetes = Skill::factory()->create(['name' => 'Kubernetes']);
    SkillAlias::factory()->for($kubernetes)->create(['alias' => 'k8s']);
    Skill::factory()->create(['name' => 'Laravel']);
    Skill::factory()->create(['name' => 'Redis']);

    JobSkillExtractor::fake([extractedJobSkills([
        'required_skills' => ['postgres', 'k8s', 'Laravel', 'FooQL', 'Terraform'],
        'nice_to_have_skills' => ['Redis', 'BarDB'],
        'seniority' => 'senior',
        'years_required' => 5,
    ])])->preventStrayPrompts();

    $posting = extractablePosting(
        'Senior Backend Engineer',
        "You will work with postgres, k8s and Laravel.\nExperience with Redis, FooQL and BarDB is a plus.",
    );

    app(ExtractPostingSkills::class)->handle($posting);

    $posting->refresh();
    $requirements = $posting->skills()->get()
        ->mapWithKeys(fn (Skill $skill): array => [$skill->name => $skill->pivot->is_required]);

    expect($requirements->all())->toBe([
        'PostgreSQL' => true,
        'Kubernetes' => true,
        'Laravel' => true,
        'Redis' => false,
    ])
        ->and($posting->extracted_skills['unknown'])->toEqual([
            ['name' => 'BarDB', 'normalized' => 'bardb', 'required' => false],
            ['name' => 'FooQL', 'normalized' => 'fooql', 'required' => true],
        ])
        ->and($posting->extracted_skills['ungrounded'])->toBe(['Terraform'])
        ->and($posting->extracted_seniority)->toBe(SeniorityLevel::Senior)
        ->and($posting->years_required)->toBe(5)
        ->and($posting->skills_text_hash)->toBe($posting->text_hash)
        ->and($posting->skills_prompt_version)->toBe(JobSkillExtractor::PROMPT_VERSION)
        ->and($posting->skills_extracted_at)->not->toBeNull()
        ->and($posting->skills_failed_at)->toBeNull()
        ->and(Skill::query()->count())->toBe(4);
    JobSkillExtractor::assertPromptedTimes(1);
});

test('a posting with an unchanged text is not sent again', function () {
    JobSkillExtractor::fake([extractedJobSkills(['required_skills' => ['Laravel']])])->preventStrayPrompts();
    $posting = extractablePosting('Backend Engineer', 'You will work with Laravel.');
    $extract = app(ExtractPostingSkills::class);

    $extract->handle($posting);
    $extract->handle($posting->refresh());

    JobSkillExtractor::assertPromptedTimes(1);
});

test('postings with the same text share one call', function () {
    Skill::factory()->create(['name' => 'Laravel']);
    Skill::factory()->create(['name' => 'Redis']);

    JobSkillExtractor::fake([extractedJobSkills([
        'required_skills' => ['Laravel'],
        'nice_to_have_skills' => ['Redis'],
    ])])->preventStrayPrompts();

    $first = extractablePosting('Backend Engineer', 'You will work with Laravel and Redis.', ['source' => 'board-a']);
    $second = extractablePosting('Backend Engineer', 'You will work with Laravel and Redis.', ['source' => 'board-b']);

    $saved = app(ExtractPostingSkills::class)->handle($first);

    $second->refresh();
    $requirements = fn (JobPosting $posting): array => $posting->skills()->get()
        ->mapWithKeys(fn (Skill $skill): array => [$skill->name => $skill->pivot->is_required])
        ->all();

    expect($second->text_hash)->toBe($saved->text_hash)
        ->and($second->extracted_skills)->toEqual($saved->extracted_skills)
        ->and($requirements($second))->toBe(['Laravel' => true, 'Redis' => false])
        ->and($requirements($saved))->toBe(['Laravel' => true, 'Redis' => false])
        ->and($second->skills_extracted_at)->not->toBeNull()
        ->and($second->skills_failed_at)->toBeNull();
    JobSkillExtractor::assertPromptedTimes(1);
});

test('an output that fails twice is recorded and not retried', function () {
    $invalid = extractedJobSkills([
        'required_skills' => ['Laravel'],
        'seniority' => 'principal',
    ]);

    JobSkillExtractor::fake([$invalid, $invalid])->preventStrayPrompts();
    $posting = extractablePosting('Backend Engineer', 'You will work with Laravel.');
    $extract = app(ExtractPostingSkills::class);

    $extract->handle($posting);

    $posting->refresh();
    expect($posting->skills_failed_at)->not->toBeNull()
        ->and($posting->extracted_skills)->toBeNull()
        ->and($posting->skills_extracted_at)->toBeNull()
        ->and($posting->skills_text_hash)->toBe($posting->text_hash)
        ->and($posting->skills_prompt_version)->toBe(JobSkillExtractor::PROMPT_VERSION)
        ->and($posting->skills()->count())->toBe(0);
    JobSkillExtractor::assertPromptedTimes(2);

    $extract->handle($posting->refresh());

    JobSkillExtractor::assertPromptedTimes(2);
});
