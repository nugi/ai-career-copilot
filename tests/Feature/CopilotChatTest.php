<?php

use App\Ai\Agents\CareerCopilot;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;

/**
 * The payloads of a server-sent event body, in order, without the "data: " prefix.
 *
 * @return Collection<int, string>
 */
function copilotStreamPayloads(string $body): Collection
{
    return collect(explode("\n\n", $body))
        ->filter(fn (string $block): bool => str_starts_with($block, 'data: '))
        ->map(fn (string $block): string => substr($block, strlen('data: ')))
        ->values();
}

/**
 * Store a copilot conversation of the user that holds one user message.
 */
function storeCopilotConversation(User $user, string $message): string
{
    $store = resolve(ConversationStore::class);
    $participantType = Conversation::participantType($user);
    $participantId = Conversation::participantKey($user);

    $conversationId = $store->storeConversation($participantType, $participantId, $message);
    $store->storeUserMessage($conversationId, $participantType, $participantId, CareerCopilot::class, new UserMessage($message));

    return $conversationId;
}

test('guests are redirected to the login page', function () {
    $this->get(route('copilot.show'))->assertRedirect(route('login'));
});

test('the page lists only the user\'s conversations', function () {
    $user = User::factory()->onboarded()->create();
    $mine = storeCopilotConversation($user, 'Which role fits me?');
    storeCopilotConversation(User::factory()->onboarded()->create(), 'Someone else asks.');

    $this->actingAs($user)
        ->get(route('copilot.show'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('copilot')
            ->has('conversations', 1)
            ->where('conversations.0.id', $mine)
            ->where('conversations.0.title', 'Which role fits me?')
            ->where('conversation', null));
});

test('an open conversation shows the user and assistant text', function () {
    $user = User::factory()->onboarded()->create();
    $conversationId = storeCopilotConversation($user, 'What should I learn next?');

    $this->actingAs($user)
        ->get(route('copilot.show', $conversationId))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('copilot')
            ->where('conversation.id', $conversationId)
            ->where('conversation.messages.0.role', 'user')
            ->where('conversation.messages.0.content', 'What should I learn next?')
            ->has('conversation.messages', 1));
});

test('a conversation of another user is not found', function () {
    CareerCopilot::fake();
    $otherConversationId = storeCopilotConversation(User::factory()->onboarded()->create(), 'Private question.');
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->get(route('copilot.show', $otherConversationId))
        ->assertNotFound();

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?', 'conversation_id' => $otherConversationId])
        ->assertNotFound();

    CareerCopilot::assertNeverPrompted();
});

test('a new message starts a conversation and streams its id first', function () {
    CareerCopilot::fake(['Start with one portfolio project.']);
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?']);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream');

    $payloads = copilotStreamPayloads($response->streamedContent());
    $conversation = json_decode($payloads->first(), true);

    expect($conversation)->toMatchArray(['type' => 'conversation'])
        ->and(Conversation::query()->whereKey($conversation['id'])->where('participant_id', $user->id)->exists())->toBeTrue()
        ->and($payloads->contains(fn (string $payload): bool => str_contains($payload, '"type":"text_delta"')))->toBeTrue()
        ->and($payloads->last())->toBe('[DONE]');

    CareerCopilot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What should I learn next?')
        && $prompt->agent instanceof CareerCopilot
        && $prompt->agent->user->is($user));
});

test('a tool call streams a status label', function () {
    CareerCopilot::fake([new ToolCall('call_1', 'GetUserProfile', []), 'Your profile is complete.']);
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What is my profile?']);

    $payloads = copilotStreamPayloads($response->streamedContent());

    expect($payloads->map(fn (string $payload): mixed => json_decode($payload, true))->contains(
        fn (mixed $event): bool => is_array($event) && ($event['type'] ?? null) === 'status' && ($event['label'] ?? null) === 'Reading your profile…',
    ))->toBeTrue()
        ->and($payloads->contains(fn (string $payload): bool => str_contains($payload, '"type":"tool_call"')
            || str_contains($payload, '"type":"tool_result"')))->toBeFalse();
});

test('the second turn sends the first turn\'s messages', function () {
    CareerCopilot::fake(['Start with one portfolio project.', 'Then build an API project.']);
    $user = User::factory()->onboarded()->create();

    $first = copilotStreamPayloads($this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->streamedContent());
    $conversationId = json_decode($first->first(), true)['id'];

    $history = fn (): array => collect(CareerCopilot::make(user: $user)->continue($conversationId, as: $user)->messages())
        ->map(fn ($message): string => $message->content)
        ->all();

    expect($history())->toBe(['What should I learn next?', 'Start with one portfolio project.']);

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'And then?', 'conversation_id' => $conversationId])
        ->assertOk()
        ->streamedContent();

    expect($history())->toBe([
        'What should I learn next?',
        'Start with one portfolio project.',
        'And then?',
        'Then build an API project.',
    ]);
});

test('advisor redirects to copilot', function () {
    $this->actingAs(User::factory()->onboarded()->create())
        ->get('/advisor')
        ->assertRedirect(route('copilot.show'))
        ->assertStatus(301);
});

test('users who have not finished onboarding get 409 without streaming an answer', function (User $user) {
    CareerCopilot::fake();

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertConflict()
        ->assertJson(['message' => 'Finish onboarding first.']);

    CareerCopilot::assertNeverPrompted();
})->with([
    'no profile' => fn () => User::factory()->create(),
    'incomplete profile' => fn () => User::factory()->has(Profile::factory()->incomplete())->create(),
]);

test('a missing or over-long message is rejected with 422', function (string $message, string $error) {
    CareerCopilot::fake();

    $this->actingAs(User::factory()->onboarded()->create())
        ->postJson(route('copilot.stream'), ['message' => $message])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message' => $error]);

    CareerCopilot::assertNeverPrompted();
})->with([
    'missing' => ['', 'The message field is required.'],
    'too long' => [str_repeat('a', 2001), 'The message field must not be greater than 2000 characters.'],
]);

test('an outage on every provider is streamed as an error event', function () {
    Exceptions::fake();
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['type' => 'server_error', 'message' => 'The server is overloaded.']], 503),
        'api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded.']], 529),
    ]);

    $response = $this->actingAs(User::factory()->onboarded()->create())
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?']);

    $response->assertOk();

    $payloads = copilotStreamPayloads($response->streamedContent());

    expect($payloads->get(1))->toBe(json_encode([
        'type' => 'error',
        'message' => 'The copilot is unavailable. Try again in a minute.',
    ]))
        ->and($payloads->last())->toBe('[DONE]');

    Exceptions::assertReported(ProviderOverloadedException::class);
});

test('the copilot returns 429 to the 11th message in a minute without asking the agent', function () {
    CareerCopilot::fake();
    $user = User::factory()->onboarded()->create();

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user)
            ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
            ->assertOk()
            ->streamedContent();
    }

    $this->actingAs($user)
        ->postJson(route('copilot.stream'), ['message' => 'What should I learn next?'])
        ->assertTooManyRequests();

    CareerCopilot::assertPromptedTimes(10);
});
