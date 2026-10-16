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

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the instructions are the v1 prompt file and contain no profile', function () {
    $advisor = advisor();

    expect((string) $advisor->instructions())
        ->toBe(file_get_contents(resource_path('prompts/career-advisor/v1.md')))
        ->and($advisor->promptVersion())->toBe('v1');
});

test('the profile goes in the user message and the instructions stay fixed', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('Build one API project.'))]);

    $user = User::factory()->has(Profile::factory()->state([
        'headline' => 'Backend engineer at Acme Corp',
        'target_roles' => ['Backend Engineer', 'Platform Engineer'],
        'preferred_locations' => ['Jakarta'],
        'work_arrangements' => [WorkArrangement::Remote, WorkArrangement::Onsite],
        'min_salary' => 27_500_000,
    ]))->create();
    $user->skills()->attach(Skill::factory()->create(['name' => 'PostgreSQL']), ['proficiency' => SkillProficiency::Intermediate]);
    $user->skills()->attach(Skill::factory()->create(['name' => 'Laravel']), ['proficiency' => SkillProficiency::Expert]);

    $instructions = file_get_contents(resource_path('prompts/career-advisor/v1.md'));
    $userMessage = <<<'TEXT'
        <user_profile>
        - Target roles: Backend Engineer, Platform Engineer
        - Preferred locations: Jakarta
        - Work arrangements: Remote, On-site
        - Skills: Laravel (Expert), PostgreSQL (Intermediate)
        </user_profile>

        <question>
        What should I learn next?
        </question>
        TEXT;

    (new CareerAdvisor($user))->prompt('What should I learn next?');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com')
        && $request['input'][0]['content'] === $instructions
        && $request['input'][1]['content'][0]['text'] === $userMessage
        && ! str_contains($request['input'][1]['content'][0]['text'], 'Acme Corp')
        && ! str_contains($request['input'][1]['content'][0]['text'], '27500000'));
});

test('preferences the user left empty are marked as not set', function () {
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('Build one API project.'))]);

    $user = User::factory()->has(Profile::factory()->state([
        'target_roles' => [],
        'preferred_locations' => [],
        'work_arrangements' => [],
    ]))->create();

    (new CareerAdvisor($user))->prompt('What should I learn next?');

    $profileBlock = <<<'TEXT'
        <user_profile>
        - Target roles: not set
        - Preferred locations: not set
        - Work arrangements: not set
        - Skills: not set
        </user_profile>
        TEXT;

    Http::assertSent(fn (Request $request) => str_starts_with($request['input'][1]['content'][0]['text'], $profileBlock));
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

    $instructions = file_get_contents(resource_path('prompts/career-advisor/v1.md'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com') && $request['model'] === 'gpt-6-luna');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com')
        && $request['model'] === 'claude-haiku-5-5'
        && $request['system'] === $instructions);
});

test('thinking is turned off for both providers', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    advisor()->prompt('What should I learn next?');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com') && $request['reasoning']['effort'] === 'none');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com') && $request['thinking']['type'] === 'disabled');
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
        && $context['exception'] === ProviderOverloadedException::class
        && $context['reason'] === 'unavailable');
});

test('exhausted credits fail over to Anthropic and log an error', function (int $status, array $body) {
    Log::spy();
    Http::fake([
        'api.openai.com/*' => Http::response($body, $status),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    $response = advisor()->prompt('What should I learn next?');

    expect($response->meta->provider)->toBe('anthropic');
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'ai.agent.failed_over'
        && $context['provider'] === 'openai'
        && $context['reason'] === 'out_of_credits');
})->with([
    'quota used up' => [429, ['error' => ['message' => 'You exceeded your current quota.', 'type' => 'insufficient_quota', 'code' => 'insufficient_quota']]],
    'payment required' => [402, ['error' => ['message' => 'Payment required.', 'type' => 'billing_error']]],
]);

test('a rate limit from OpenAI fails over with a warning and not an error', function () {
    Log::spy();
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit reached.', 'type' => 'requests', 'code' => 'rate_limit_exceeded']], 429),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    advisor()->prompt('What should I learn next?');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'ai.agent.failed_over'
        && $context['reason'] === 'unavailable');
    Log::shouldNotHaveReceived('error');
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
