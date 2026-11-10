<?php

use App\Actions\Fit\ExplainFit;
use App\Ai\Agents\FitExplainer;
use App\Enums\ResumeSection;
use App\Jobs\CheckFitFaithfulness;
use App\Models\AiUsage;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeChunk;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function (): void {
    config([
        'ai-routing.global_daily_budget_usd' => null,
        'ai-routing.daily_budget_usd' => null,
        'ai-routing.fit_explanations.faithfulness_sample_rate' => 0,
    ]);
    Queue::fake();
});

/**
 * A 1536-dimension vector with the given leading values.
 *
 * @param  list<float|int>  $values
 */
function fitVector(array $values): string
{
    return '['.implode(',', [...$values, ...array_fill(0, 1536 - count($values), 0)]).']';
}

/**
 * A user with a parsed primary resume whose chunks have the given contents and vectors, in position order.
 *
 * @param  array<string, string>  $chunks  Content mapped to its vector.
 * @return array{User, Resume, list<ResumeChunk>}
 */
function userWithChunks(array $chunks): array
{
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->for($user)->primary()->parsed()->create();
    $saved = [];
    $position = 0;

    foreach ($chunks as $content => $vector) {
        $saved[] = ResumeChunk::factory()->for($resume)->create([
            'position' => $position++, 'section' => ResumeSection::Experience, 'content' => $content,
            'embedding' => $vector, 'embedding_model' => 'text-embedding-3-small', 'embedded_at' => now(),
        ]);
    }

    return [$user, $resume, $saved];
}

function fitPosting(string $title = 'Senior Laravel Engineer'): JobPosting
{
    return JobPosting::factory()->remote()->create([
        'title' => $title, 'description' => 'Build Laravel APIs on PostgreSQL.', 'expires_at' => null,
        'embedding' => fitVector([1]), 'embedding_model' => 'text-embedding-3-small', 'embedded_at' => now(),
        'embedding_text_hash' => hash('sha256', $title), 'embedded_text' => $title,
    ]);
}

/**
 * An agent output with the given fields filled.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fitOutput(array $overrides = []): array
{
    return [
        'verdict' => 'strong_fit',
        'summary' => 'You have built Laravel APIs on PostgreSQL.',
        'reasons' => [],
        'missing_requirements' => [],
        ...$overrides,
    ];
}

test('a user without a parsed primary resume gets no explanation and no call', function () {
    FitExplainer::fake()->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();

    $explanation = app(ExplainFit::class)->handle($user, fitPosting());

    expect($explanation->status)->toBe('no_resume');
    FitExplainer::assertNeverPrompted();
});

test('a resume without embedded chunks is not ready', function () {
    FitExplainer::fake()->preventStrayPrompts();
    [$user] = userWithChunks([]);

    expect(app(ExplainFit::class)->handle($user, fitPosting())->status)->toBe('resume_not_ready');
    FitExplainer::assertNeverPrompted();
});

test('the five nearest chunks of the primary resume are sent as numbered excerpts, nearest first', function () {
    FitExplainer::fake([fitOutput()]);
    [$user] = userWithChunks([
        'Far chunk' => fitVector([0, 1]),
        'Nearest chunk' => fitVector([1]),
        'Second chunk' => fitVector([1, 0.2]),
        'Third chunk' => fitVector([1, 0.5]),
        'Fourth chunk' => fitVector([1, 0.8]),
        'Fifth chunk' => fitVector([1, 1]),
    ]);
    [$other] = userWithChunks(['Another user chunk' => fitVector([1])]);

    app(ExplainFit::class)->handle($user, fitPosting());

    FitExplainer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains("<excerpt id=\"1\">\nNearest chunk")
        && $prompt->contains("<excerpt id=\"5\">\nFifth chunk")
        && ! $prompt->contains('Far chunk')
        && ! $prompt->contains('Another user chunk')
        && $prompt->contains('<title>Senior Laravel Engineer</title>'));
});

test('a citation of an excerpt that was not sent is removed and a reason left with none is dropped', function () {
    FitExplainer::fake([fitOutput([
        'reasons' => [
            ['claim' => 'You built Laravel APIs.', 'excerpt_ids' => [1, 9]],
            ['claim' => 'You led a team of ten.', 'excerpt_ids' => [7]],
            ['claim' => 'You used PostgreSQL.', 'excerpt_ids' => [2, 2]],
        ],
        'missing_requirements' => ['Kubernetes', '  '],
    ])]);
    [$user, , $chunks] = userWithChunks([
        'Built Laravel APIs for checkout.' => fitVector([1]),
        'Designed a PostgreSQL schema.' => fitVector([1, 0.5]),
    ]);

    $explanation = app(ExplainFit::class)->handle($user, fitPosting());

    expect($explanation->status)->toBe('ready')
        ->and($explanation->verdict)->toBe('strong_fit')
        ->and($explanation->droppedReasons)->toBe(1)
        ->and($explanation->downgraded)->toBeFalse()
        ->and($explanation->missingRequirements)->toBe(['Kubernetes'])
        ->and($explanation->reasons)->toBe([
            ['claim' => 'You built Laravel APIs.', 'excerpts' => [
                ['id' => $chunks[0]->id, 'section' => 'experience', 'content' => 'Built Laravel APIs for checkout.'],
            ]],
            ['claim' => 'You used PostgreSQL.', 'excerpts' => [
                ['id' => $chunks[1]->id, 'section' => 'experience', 'content' => 'Designed a PostgreSQL schema.'],
            ]],
        ]);
});

test('a fit verdict with no grounded reason left is lowered to a weak fit', function () {
    FitExplainer::fake([fitOutput(['reasons' => [['claim' => 'You know Go.', 'excerpt_ids' => [4]]]])]);
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);

    $explanation = app(ExplainFit::class)->handle($user, fitPosting());

    expect($explanation->verdict)->toBe('weak_fit')
        ->and($explanation->downgraded)->toBeTrue()
        ->and($explanation->reasons)->toBe([])
        ->and($explanation->droppedReasons)->toBe(1);
});

test('an unfit job gets an honest weak fit with its requirements listed as missing', function () {
    FitExplainer::fake([fitOutput([
        'verdict' => 'weak_fit',
        'summary' => 'This is a nursing role, and your resume is about software.',
        'missing_requirements' => ['Registered nurse licence', 'ICU experience'],
    ])]);
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);

    $explanation = app(ExplainFit::class)->handle($user, fitPosting('ICU Registered Nurse'));

    expect($explanation->verdict)->toBe('weak_fit')
        ->and($explanation->downgraded)->toBeFalse()
        ->and($explanation->reasons)->toBe([])
        ->and($explanation->missingRequirements)->toBe(['Registered nurse licence', 'ICU experience']);
});

test('an answer is cached and a changed posting asks again', function () {
    $reason = ['claim' => 'You built Laravel APIs.', 'excerpt_ids' => [1]];
    FitExplainer::fake([fitOutput(['reasons' => [$reason]]), fitOutput(['verdict' => 'partial_fit', 'reasons' => [$reason]])]);
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);
    $posting = fitPosting();
    $explain = app(ExplainFit::class);

    $explain->handle($user, $posting);
    $cached = $explain->handle($user, $posting);
    $posting->update(['description' => 'Build Go services.']);
    $changed = $explain->handle($user, $posting->fresh());

    expect($cached->verdict)->toBe('strong_fit')
        ->and($changed->verdict)->toBe('partial_fit');
    FitExplainer::assertPromptedTimes(2);
});

test('a changed explainer model asks again', function (): void {
    FitExplainer::fake([fitOutput(), fitOutput()]);
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);
    $posting = fitPosting();
    $explain = app(ExplainFit::class);

    expect($explain->handle($user, $posting)->status)->toBe('ready');
    config(['ai-routing.tiers.cheap.openai' => 'gpt-6.1-sol']);
    expect($explain->handle($user, $posting)->status)->toBe('ready');

    FitExplainer::assertPromptedTimes(2);
});

test('a cached answer is served after the user budget is spent', function (): void {
    FitExplainer::fake([fitOutput()]);
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);
    $posting = fitPosting();
    $explain = app(ExplainFit::class);
    $first = $explain->handle($user, $posting);
    config(['ai-routing.daily_budget_usd' => 0.01]);
    AiUsage::factory()->create(['user_id' => $user->id, 'cost_usd' => 0.02, 'created_at' => now()]);

    expect($explain->handle($user, $posting)->toArray())->toBe($first->toArray())
        ->and($first->status)->toBe('ready');
    FitExplainer::assertPromptedTimes(1);
});

test('an invalid output is retried once with its errors and then fails', function () {
    FitExplainer::fake([fitOutput(['verdict' => 'great']), fitOutput(['verdict' => 'great'])]);
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);

    $explanation = app(ExplainFit::class)->handle($user, fitPosting());

    expect($explanation->status)->toBe('failed');
    FitExplainer::assertPromptedTimes(2);
    FitExplainer::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('<validation_errors>'));
});

test('a spent daily budget of the user stops the call', function () {
    config(['ai-routing.daily_budget_usd' => 0.01]);
    FitExplainer::fake()->preventStrayPrompts();
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);
    AiUsage::factory()->create(['user_id' => $user->id, 'cost_usd' => 0.02, 'created_at' => now()]);

    expect(app(ExplainFit::class)->handle($user, fitPosting())->status)->toBe('budget');
    FitExplainer::assertNeverPrompted();
});

test('a posting without a current vector is not ready and makes no call', function (?string $vector, ?string $model): void {
    Embeddings::fake()->preventStrayEmbeddings();
    FitExplainer::fake()->preventStrayPrompts();
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);
    $posting = JobPosting::factory()->remote()->withEmbedding()->create([
        'embedding' => $vector, 'embedding_model' => $model, 'expires_at' => null,
        'embedded_at' => $vector === null ? null : now(),
        'embedding_text_hash' => $vector === null ? null : hash('sha256', 'Posting'),
        'embedded_text' => $vector === null ? null : 'Posting',
    ]);
    config(['ai-routing.daily_budget_usd' => 0.01]);
    AiUsage::factory()->create(['user_id' => $user->id, 'cost_usd' => 0.02, 'created_at' => now()]);

    expect(app(ExplainFit::class)->handle($user, $posting)->status)->toBe('posting_not_ready');
    Embeddings::assertNothingGenerated();
    FitExplainer::assertNeverPrompted();
})->with([
    'missing vector' => [null, null],
    'outdated model' => [fitVector([1]), 'old-embedding-model'],
]);

test('a sampled answer queues a faithfulness check with the claims and the excerpt texts', function () {
    config(['ai-routing.fit_explanations.faithfulness_sample_rate' => 1.0]);
    FitExplainer::fake([fitOutput(['reasons' => [['claim' => 'You built Laravel APIs.', 'excerpt_ids' => [1]]]])]);
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);

    app(ExplainFit::class)->handle($user, fitPosting());

    Queue::assertPushed(CheckFitFaithfulness::class, fn (CheckFitFaithfulness $job): bool => $job->reasons[0]['claim'] === 'You built Laravel APIs.'
        && $job->reasons[0]['excerpts'][0]['content'] === 'Built Laravel APIs.');
});

test('a sample rate of zero queues no check', function () {
    FitExplainer::fake([fitOutput(['reasons' => [['claim' => 'You built Laravel APIs.', 'excerpt_ids' => [1]]]])]);
    [$user] = userWithChunks(['Built Laravel APIs.' => fitVector([1])]);

    app(ExplainFit::class)->handle($user, fitPosting());

    Queue::assertNotPushed(CheckFitFaithfulness::class);
});
