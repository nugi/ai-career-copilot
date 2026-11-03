<?php

use App\Actions\Jobs\EmbedProfile;
use App\Actions\Jobs\ProfileEmbeddingText;
use App\Enums\SkillProficiency;
use App\Enums\SkillSource;
use App\Models\AiUsage;
use App\Models\Profile;
use App\Models\Skill;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('query text selects the ten best skills and sorts their names after the roles', function () {
    $profile = Profile::factory()->create(['target_roles' => ['Backend Engineer', 'Platform Engineer']]);
    $proficiencies = [
        'Zulu' => SkillProficiency::Expert,
        'Yankee' => SkillProficiency::Expert,
        'Xray' => SkillProficiency::Advanced,
        'Whiskey' => SkillProficiency::Advanced,
        'Victor' => SkillProficiency::Intermediate,
        'Uniform' => SkillProficiency::Intermediate,
        'Foxtrot' => SkillProficiency::Beginner,
        'Echo' => SkillProficiency::Beginner,
        'Delta' => SkillProficiency::Beginner,
        'Charlie' => SkillProficiency::Beginner,
        'Bravo' => SkillProficiency::Beginner,
        'Alpha' => SkillProficiency::Beginner,
    ];

    foreach ($proficiencies as $name => $proficiency) {
        $profile->user->skills()->attach(Skill::factory()->create(['name' => $name]), [
            'proficiency' => $proficiency,
            'source' => SkillSource::Profile,
        ]);
    }

    $builder = app(ProfileEmbeddingText::class);
    $text = "Backend Engineer, Platform Engineer\nSkills: Alpha, Bravo, Charlie, Delta, Uniform, Victor, Whiskey, Xray, Yankee, Zulu";

    expect($builder->text($profile))->toBe($text);
    $profile->user->setRelation('skills', $profile->user->skills->reverse());
    expect($builder->text($profile))->toBe($text);
});

test('the first call saves the query vector and provenance and the second call reuses it', function () {
    config(['ai-routing.global_daily_budget_usd' => null, 'ai-routing.daily_budget_usd' => 0]);
    $requests = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests[] = $prompt->inputs;

        return [array_fill(0, 1536, 0.1)];
    });
    $profile = Profile::factory()->create(['target_roles' => ['Backend Engineer']]);
    $vector = '['.implode(',', array_fill(0, 1536, 0.1)).']';
    $action = app(EmbedProfile::class);

    expect($action->handle($profile))->toBe($vector);
    $profile->refresh();
    expect($requests)->toBe([['Backend Engineer']])
        ->and($profile->match_embedding)->toBe($vector)
        ->and($profile->match_embedding_model)->toBe(config('ai-routing.embeddings.model'))
        ->and($profile->match_embedding_hash)->toBe(hash('sha256', 'Backend Engineer'));

    expect($action->handle($profile))->toBe($vector)
        ->and($requests)->toHaveCount(1);
});

test('changed skills roles and models each cause a new embedding', function () {
    config(['ai-routing.global_daily_budget_usd' => null]);
    $requests = [];
    Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$requests): array {
        $requests[] = [$prompt->inputs, $prompt->model];

        return [array_fill(0, 1536, count($requests) / 10)];
    });
    $profile = Profile::factory()->create(['target_roles' => ['Backend Engineer']]);
    $action = app(EmbedProfile::class);
    $model = config('ai-routing.embeddings.model');
    $action->handle($profile);

    $profile->user->skills()->attach(Skill::factory()->create(['name' => 'PHP']), [
        'proficiency' => SkillProficiency::Expert,
        'source' => SkillSource::Profile,
    ]);
    $action->handle($profile->refresh());
    expect($profile->match_embedding_hash)->toBe(hash('sha256', "Backend Engineer\nSkills: PHP"));

    $profile->update(['target_roles' => ['Platform Engineer']]);
    $action->handle($profile);
    expect($profile->match_embedding_hash)->toBe(hash('sha256', "Platform Engineer\nSkills: PHP"));

    config(['ai-routing.embeddings.model' => 'changed-embedding-model']);
    $vector = $action->handle($profile);

    expect($requests)->toBe([
        [['Backend Engineer'], $model],
        [["Backend Engineer\nSkills: PHP"], $model],
        [["Platform Engineer\nSkills: PHP"], $model],
        [["Platform Engineer\nSkills: PHP"], 'changed-embedding-model'],
    ])->and($profile->fresh()->match_embedding_model)->toBe('changed-embedding-model')
        ->and($profile->fresh()->match_embedding)->toBe($vector);
});

test('an exceeded global budget blocks a missing vector but not a current vector', function () {
    config(['ai-routing.global_daily_budget_usd' => 1.00]);
    AiUsage::factory()->create(['user_id' => null, 'cost_usd' => 1.00]);
    Embeddings::fake();
    $profile = Profile::factory()->create(['target_roles' => ['Backend Engineer']]);
    $action = app(EmbedProfile::class);

    expect(fn (): string => $action->handle($profile))->toThrow(RuntimeException::class);
    Embeddings::assertNothingGenerated();

    $vector = '['.implode(',', array_fill(0, 1536, 0.1)).']';
    $profile->match_embedding = $vector;
    $profile->match_embedding_model = config('ai-routing.embeddings.model');
    $profile->match_embedding_hash = hash('sha256', 'Backend Engineer');
    $profile->save();

    expect($action->handle($profile->fresh()))->toBe($vector);
    Embeddings::assertNothingGenerated();
});

test('the query vector is hidden from profile arrays', function () {
    $profile = Profile::factory()->create();
    $profile->match_embedding = '['.implode(',', array_fill(0, 1536, 0.1)).']';

    expect($profile->toArray())->not->toHaveKey('match_embedding');
});
