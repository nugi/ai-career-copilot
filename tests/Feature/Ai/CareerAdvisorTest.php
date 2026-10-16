<?php

use App\Ai\Agents\CareerAdvisor;
use App\Ai\Prompts\Instructions;
use App\Enums\SkillProficiency;
use App\Enums\WorkArrangement;
use App\Models\Profile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

/**
 * A Responses API body from OpenAI that answers with the given text.
 *
 * @return array<string, mixed>
 */
function openAiAnswer(string $text): array
{
    return [
        'id' => 'resp_123',
        'object' => 'response',
        'status' => 'completed',
        'model' => 'gpt-6-luna',
        'output' => [[
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text]],
        ]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

/**
 * A Messages API body from Anthropic that answers with the given text.
 *
 * @return array<string, mixed>
 */
function anthropicAnswer(string $text): array
{
    return [
        'id' => 'msg_123',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ];
}

/**
 * A career advisor for a user who has finished onboarding.
 */
function advisor(): CareerAdvisor
{
    return new CareerAdvisor(User::factory()->onboarded()->create());
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the instructions are the v1 prompt followed by the user profile without the headline or salary', function () {
    $user = User::factory()->has(Profile::factory()->state([
        'headline' => 'Backend engineer at Acme Corp',
        'target_roles' => ['Backend Engineer', 'Platform Engineer'],
        'preferred_locations' => ['Jakarta'],
        'work_arrangements' => [WorkArrangement::Remote, WorkArrangement::Onsite],
        'min_salary' => 27_500_000,
    ]))->create();
    $user->skills()->attach(Skill::factory()->create(['name' => 'PostgreSQL']), ['proficiency' => SkillProficiency::Intermediate]);
    $user->skills()->attach(Skill::factory()->create(['name' => 'Laravel']), ['proficiency' => SkillProficiency::Expert]);

    $advisor = new CareerAdvisor($user);

    expect((string) $advisor->instructions())
        ->toStartWith(file_get_contents(resource_path('prompts/career-advisor/v1.md')))
        ->toEndWith(<<<'MARKDOWN'
            ## About this user

            - Target roles: Backend Engineer, Platform Engineer
            - Preferred locations: Jakarta
            - Work arrangements: Remote, On-site
            - Skills: Laravel (Expert), PostgreSQL (Intermediate)

            MARKDOWN)
        ->not->toContain('Acme Corp')
        ->not->toContain('27500000')
        ->and($advisor->promptVersion())->toBe('v1');
});

test('preferences the user left empty are marked as not set', function () {
    $user = User::factory()->has(Profile::factory()->state([
        'target_roles' => [],
        'preferred_locations' => [],
        'work_arrangements' => [],
    ]))->create();

    expect((string) (new CareerAdvisor($user))->instructions())->toEndWith(<<<'MARKDOWN'
        - Target roles: not set
        - Preferred locations: not set
        - Work arrangements: not set
        - Skills: not set

        MARKDOWN);
});

test('a missing instructions version throws', function () {
    Instructions::load('career-advisor', 'v99');
})->throws(FileNotFoundException::class);

test('OpenAI answers first when it is available', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(openAiAnswer('Build one API project.')),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Unused answer.')),
    ]);

    $response = advisor()->prompt('What should I learn next?');

    expect($response->text)->toBe('Build one API project.')
        ->and($response->meta->provider)->toBe('openai');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com'));
});

test('each provider is asked with its pinned model', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    advisor()->prompt('What should I learn next?');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com') && $request['model'] === 'gpt-6-luna');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com') && $request['model'] === 'claude-haiku-5-5');
});

test('a connection failure to OpenAI fails over to Anthropic', function () {
    Http::fake([
        'api.openai.com/*' => Http::failedConnection(),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    $response = advisor()->prompt('What should I learn next?');

    expect($response->text)->toBe('Start with one project.')
        ->and($response->meta->provider)->toBe('anthropic');
});

test('an overloaded OpenAI fails over to Anthropic and logs the failover', function () {
    Log::spy();
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    $response = advisor()->prompt('What should I learn next?');

    expect($response->text)->toBe('Start with one project.')
        ->and($response->meta->provider)->toBe('anthropic');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'ai.agent.failed_over'
        && $context['agent'] === CareerAdvisor::class
        && $context['provider'] === 'openai'
        && $context['exception'] === ProviderOverloadedException::class);
});

test('a rejected OpenAI key does not fail over to Anthropic', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Incorrect API key provided.']], 401),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    expect(fn () => advisor()->prompt('What should I learn next?'))->toThrow(RequestException::class);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com'));
});

test('the log records the prompt version and metadata but not the question or the answer', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('Private answer text.'))]);

    advisor()->prompt('My private question about salary.');

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        $logged = json_encode($context);

        return $message === 'ai.agent.prompted'
            && $context['prompt_version'] === 'v1'
            && $context['provider'] === 'openai'
            && ! str_contains($logged, 'My private question')
            && ! str_contains($logged, 'Private answer text');
    });
});
