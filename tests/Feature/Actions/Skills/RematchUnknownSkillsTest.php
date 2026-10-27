<?php

use App\Actions\Skills\RematchUnknownSkills;
use App\Ai\Agents\JobSkillExtractor;
use App\Jobs\RematchSkills;
use App\Models\JobPosting;
use App\Models\Skill;
use App\Models\SkillAlias;
use Illuminate\Support\Facades\Queue;

test('a verified skill attaches matching unknown names with their stored group', function () {
    Queue::fake([RematchSkills::class]);
    JobSkillExtractor::fake()->preventStrayPrompts();
    $extractedAt = now()->subDay()->startOfSecond();
    $postings = collect([true, false])->map(fn (bool $required): JobPosting => JobPosting::factory()->create([
        'extracted_skills' => [
            'required' => $required ? ['FooQL'] : [],
            'nice_to_have' => $required ? [] : ['FooQL'],
            'unknown' => [
                ['name' => 'FooQL', 'normalized' => 'fooql', 'required' => $required],
                ['name' => 'BarDB', 'normalized' => 'bardb', 'required' => false],
            ],
            'ungrounded' => ['Invented'],
        ],
        'skills_extracted_at' => $extractedAt,
        'skills_text_hash' => str_repeat('a', 64),
        'skills_prompt_version' => 'v1',
    ]));
    $verified = Skill::factory()->create(['name' => 'FooQL']);
    $unverified = Skill::factory()->unverified()->create(['name' => 'BarDB']);
    $originals = $postings->map(fn (JobPosting $posting): array => $posting->extracted_skills);

    $changed = app(RematchUnknownSkills::class)->handle();

    expect($changed)->toBe(2);
    foreach ($postings as $index => $posting) {
        $posting->refresh();
        expect($posting->skills()->sole()->id)->toBe($verified->id)
            ->and($posting->skills()->sole()->pivot->is_required)->toBe($index === 0)
            ->and($posting->skills()->whereKey($unverified->id)->exists())->toBeFalse()
            ->and($posting->extracted_skills)->toEqual([
                ...$originals[$index],
                'unknown' => [['name' => 'BarDB', 'normalized' => 'bardb', 'required' => false]],
            ])
            ->and($posting->skills_extracted_at->equalTo($extractedAt))->toBeTrue()
            ->and($posting->skills_text_hash)->toBe(str_repeat('a', 64))
            ->and($posting->skills_prompt_version)->toBe('v1');
    }
    Queue::assertPushed(RematchSkills::class, 1);
    JobSkillExtractor::assertNeverPrompted();
});

test('a new alias re-matches stored names', function () {
    JobSkillExtractor::fake()->preventStrayPrompts();
    $skill = Skill::factory()->create(['name' => 'Terraform']);
    $posting = JobPosting::factory()->create([
        'extracted_skills' => [
            'required' => [],
            'nice_to_have' => ['terraform cloud'],
            'unknown' => [['name' => 'terraform cloud', 'normalized' => 'terraform cloud', 'required' => false]],
            'ungrounded' => [],
        ],
    ]);

    SkillAlias::factory()->for($skill)->create(['alias' => 'terraform cloud']);

    expect($posting->refresh()->extracted_skills['unknown'])->toBe([])
        ->and($posting->skills()->sole()->id)->toBe($skill->id)
        ->and($posting->skills()->sole()->pivot->is_required)->toBeFalse();
    JobSkillExtractor::assertNeverPrompted();
});
