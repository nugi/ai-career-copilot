<?php

use App\Ai\Agents\CareerCopilot;
use App\Ai\Prompts\Instructions;
use App\Models\User;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

test('the instructions are the current prompt file and contain no profile', function () {
    $copilot = copilot();

    expect((string) $copilot->instructions())
        ->toBe(file_get_contents(resource_path('prompts/career-copilot/v2.md')))
        ->and($copilot->promptVersion())->toBe(CareerCopilot::PROMPT_VERSION);
});

test('a missing instructions version throws', function () {
    Instructions::load('career-copilot', 'v99');
})->throws(FileNotFoundException::class);

test('OpenAI answers first when it is available', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(openAiAnswer('Build one API project.')),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Unused answer.')),
    ]);

    $response = copilot()->prompt('What should I learn next?');

    expect($response->text)->toBe('Build one API project.')
        ->and($response->meta->provider)->toBe('openai');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com'));
});

test('each provider is asked with its pinned model', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    copilot()->prompt('What should I learn next?');

    $instructions = file_get_contents(resource_path('prompts/career-copilot/v2.md'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com') && $request['model'] === 'gpt-6-luna');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com')
        && $request['model'] === 'claude-haiku-5-5'
        && $request['system'] === $instructions);
});

test('each provider is asked with the reasoning option of its model', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    copilot()->prompt('What should I learn next?');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com') && $request['reasoning']['effort'] === 'none');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com') && $request['thinking']['type'] === 'disabled');
});

test('a connection failure to OpenAI fails over to Anthropic', function () {
    Http::fake([
        'api.openai.com/*' => Http::failedConnection(),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    $response = copilot()->prompt('What should I learn next?');

    expect($response->text)->toBe('Start with one project.')
        ->and($response->meta->provider)->toBe('anthropic');
});

test('an overloaded OpenAI fails over to Anthropic and logs the failover', function () {
    Log::spy();
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    $response = copilot()->prompt('What should I learn next?');

    expect($response->text)->toBe('Start with one project.')
        ->and($response->meta->provider)->toBe('anthropic');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'ai.agent.failed_over'
        && $context['agent'] === CareerCopilot::class
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

    $response = copilot()->prompt('What should I learn next?');

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

    copilot()->prompt('What should I learn next?');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'ai.agent.failed_over'
        && $context['reason'] === 'unavailable');
    Log::shouldNotHaveReceived('error');
});

test('a rejected OpenAI key does not fail over to Anthropic', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Incorrect API key provided.']], 401),
        'api.anthropic.com/*' => Http::response(anthropicAnswer('Start with one project.')),
    ]);

    expect(fn () => copilot()->prompt('What should I learn next?'))->toThrow(RequestException::class);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.anthropic.com'));
});

test('the log records the prompt version and metadata but not the question or the answer', function () {
    Log::spy();
    Http::fake(['api.openai.com/*' => Http::response(openAiAnswer('Private answer text.'))]);

    copilot()->prompt('My private question about salary.');

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        $logged = json_encode($context);

        return $message === 'ai.agent.prompted'
            && $context['prompt_version'] === CareerCopilot::PROMPT_VERSION
            && $context['provider'] === 'openai'
            && ! str_contains($logged, 'My private question')
            && ! str_contains($logged, 'Private answer text');
    });
});

test('the copilot sends the summary and the messages after it', function () {
    $user = User::factory()->onboarded()->create();
    $participantType = Conversation::participantType($user);
    $participantId = Conversation::participantKey($user);
    $conversationId = resolve(ConversationStore::class)->storeConversation($participantType, $participantId, 'Which role fits me?');

    for ($number = 1; $number <= 14; $number++) {
        $isAssistant = $number % 2 === 0;

        ConversationMessage::query()->create([
            'id' => sprintf('00000000-0000-7000-8000-%012d', $number),
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'agent' => CareerCopilot::class,
            'role' => $isAssistant ? 'assistant' : 'user',
            'content' => sprintf('Note %02d', $number),
            'attachments' => [],
            'steps' => $isAssistant ? [[
                'content' => sprintf('Note %02d', $number),
                'tool_calls' => $number === 12 ? [[
                    'id' => 'call_search',
                    'name' => 'SearchJobs',
                    'arguments' => [],
                    'result_id' => null,
                    'result' => '{"jobs":[]}',
                ]] : [],
                'reasoning' => '',
                'replay_blocks' => [],
                'provider_tool_calls' => [],
            ]] : [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);
    }

    Conversation::query()->whereKey($conversationId)->update([
        'summary' => 'The user wants a Laravel role.',
        'summary_through' => sprintf('00000000-0000-7000-8000-%012d', 4),
    ]);

    $messages = (new CareerCopilot($user))->continue($conversationId, as: $user)->messages();
    $contents = collect($messages)->pluck('content')->implode("\n");

    expect($messages[0]->content)->toBe("Summary of the earlier conversation:\nThe user wants a Laravel role.")
        ->and($contents)->toContain('Note 05')
        ->and($contents)->toContain('Note 14')
        ->and($contents)->not->toContain('Note 04')
        ->and(collect($messages)->contains(fn (object $message): bool => $message instanceof ToolResultMessage))->toBeTrue()
        ->and(count($messages))->toBe(12);
});
