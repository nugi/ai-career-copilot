<?php

use App\Actions\Resume\ParseResume;
use App\Ai\Agents\CareerAdvisor;
use App\Ai\Agents\ResumeParser;
use App\Ai\ModelRouter;
use App\Enums\AiFeature;
use App\Enums\AiUsageStatus;
use App\Enums\ModelTier;
use App\Models\AiUsage;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

test('an embedding call writes one succeeded usage row without a user', function () {
    Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
        fn (string $input): array => array_fill(0, 1536, 0.1),
        $prompt->inputs,
    ));

    Embeddings::for(['Backend Engineer'])->dimensions(1536)->generate('openai', 'text-embedding-3-small');

    $usage = AiUsage::query()->sole();

    expect($usage->feature)->toBe(AiFeature::Embedding)
        ->and($usage->agent)->toBe('Embeddings')
        ->and($usage->status)->toBe(AiUsageStatus::Succeeded)
        ->and($usage->user_id)->toBeNull()
        ->and($usage->tier)->toBeNull()
        ->and($usage->prompt_version)->toBeNull()
        ->and($usage->provider)->toBe('openai')
        ->and($usage->model)->toBe('text-embedding-3-small')
        ->and($usage->output_tokens)->toBe(0)
        ->and($usage->latency_ms)->toBeGreaterThanOrEqual(0);
});

test('an advisor answer writes one succeeded usage row', function () {
    CareerAdvisor::fake(['Build one API project.']);
    $user = User::factory()->onboarded()->create();

    (new CareerAdvisor($user))->prompt('What should I learn next?');

    $usage = AiUsage::query()->sole();

    expect($usage->user_id)->toBe($user->id)
        ->and($usage->feature)->toBe(AiFeature::Chat)
        ->and($usage->tier)->toBe(ModelTier::Cheap)
        ->and($usage->agent)->toBe('CareerAdvisor')
        ->and($usage->prompt_version)->toBe(CareerAdvisor::PROMPT_VERSION)
        ->and($usage->status)->toBe(AiUsageStatus::Succeeded)
        ->and($usage->provider)->toBe('openai')
        ->and($usage->model)->toBe('gpt-6-luna')
        ->and($usage->invocation_id)->not->toBeNull()
        ->and($usage->input_tokens)->toBe(0)
        ->and($usage->output_tokens)->toBe(0)
        ->and($usage->latency_ms)->toBeGreaterThanOrEqual(0);
});

test('a streamed advisor answer writes one succeeded usage row', function () {
    CareerAdvisor::fake(['Build one API project.']);

    advisor()->stream('What should I learn next?')->each(fn () => null);

    $usage = AiUsage::query()->sole();

    expect($usage->feature)->toBe(AiFeature::Chat)
        ->and($usage->status)->toBe(AiUsageStatus::Succeeded)
        ->and($usage->provider)->toBe('openai')
        ->and($usage->model)->toBe('gpt-6-luna');
});

test('a resume parse writes one usage row with the resume user and the cheap tier', function () {
    ResumeParser::fake([parsedResume()])->preventStrayPrompts();
    $user = User::factory()->onboarded()->create();
    $resume = Resume::factory()->extracted()->primary()->for($user)->create();

    app(ParseResume::class)->handle($resume);

    $usage = AiUsage::query()->sole();

    expect($usage->user_id)->toBe($user->id)
        ->and($usage->feature)->toBe(AiFeature::ResumeParsing)
        ->and($usage->tier)->toBe(ModelTier::Cheap)
        ->and($usage->agent)->toBe('ResumeParser')
        ->and($usage->model)->toBe('gpt-6-luna')
        ->and($usage->status)->toBe(AiUsageStatus::Succeeded);
});

test('a failover writes a failed_over row and a succeeded row for the same invocation', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    advisor()->prompt('What should I learn next?');

    $usages = AiUsage::query()->orderBy('id')->get();

    expect($usages)->toHaveCount(2)
        ->and($usages[0]->status)->toBe(AiUsageStatus::FailedOver)
        ->and($usages[0]->provider)->toBe('openai')
        ->and($usages[0]->model)->toBe('gpt-6-luna')
        ->and($usages[0]->input_tokens)->toBe(0)
        ->and($usages[0]->output_tokens)->toBe(0)
        ->and($usages[0]->tokens_estimated)->toBeFalse()
        ->and($usages[1]->status)->toBe(AiUsageStatus::Succeeded)
        ->and($usages[1]->provider)->toBe('anthropic')
        ->and($usages[1]->tokens_estimated)->toBeFalse()
        ->and($usages[1]->invocation_id)->toBe($usages[0]->invocation_id);
});

test('a dropped connection writes a failed_over row with estimated tokens', function () {
    Http::fake([
        'api.openai.com/*' => Http::failedConnection(),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    advisor()->prompt('What should I learn next?');

    $usages = AiUsage::query()->orderBy('id')->get();

    expect($usages)->toHaveCount(2)
        ->and($usages[0]->status)->toBe(AiUsageStatus::FailedOver)
        ->and($usages[0]->tokens_estimated)->toBeTrue()
        ->and($usages[1]->status)->toBe(AiUsageStatus::Succeeded)
        ->and($usages[1]->tokens_estimated)->toBeFalse();
});

test('an outage on every provider writes a failed_over row and a failed row and still throws', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded.']], 529),
    ]);

    expect(fn () => advisor()->prompt('What should I learn next?'))->toThrow(ProviderOverloadedException::class);

    $usages = AiUsage::query()->orderBy('id')->get();

    expect($usages)->toHaveCount(2)
        ->and($usages[0]->status)->toBe(AiUsageStatus::FailedOver)
        ->and($usages[0]->provider)->toBe('openai')
        ->and($usages[1]->status)->toBe(AiUsageStatus::Failed)
        ->and($usages[1]->provider)->toBe('anthropic')
        ->and($usages[1]->invocation_id)->toBe($usages[0]->invocation_id);
});

test('a usage row that cannot be written does not fail the AI call', function () {
    Log::spy();
    AiUsage::creating(fn () => throw new RuntimeException('The usage table is unavailable.'));
    CareerAdvisor::fake(['Build one API project.']);

    $response = advisor()->prompt('What should I learn next?');

    expect($response->text)->toBe('Build one API project.')
        ->and(AiUsage::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'ai.usage.record_failed'
        && $context['exception'] === RuntimeException::class);
});

test('the recorded cost of a call is the cost of its model and tokens', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(openAiAnswer('Build one API project.')),
    ]);

    advisor()->prompt('What should I learn next?');

    $usage = AiUsage::query()->sole();

    expect($usage->model)->toBe('gpt-6-luna')
        ->and($usage->input_tokens)->toBe(10)
        ->and($usage->output_tokens)->toBe(5)
        ->and($usage->cost_usd)->toBe(app(ModelRouter::class)->cost('gpt-6-luna', 10, 5));
});
