<?php

use App\Ai\Agents\CareerCopilot;
use App\Ai\Agents\ConversationSummarizer;
use App\Ai\DailyBudget;
use App\Jobs\SummarizeConversation;
use App\Models\User;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Store a copilot conversation of the user with the given number of messages, oldest first.
 *
 * The messages alternate between user and assistant. Each message is named "Note NN", and its ID grows with NN, so the order is fixed.
 *
 * @return array{0: string, 1: list<string>} The conversation ID and the message IDs, oldest first.
 */
function storedTurns(User $user, int $count): array
{
    $participantType = Conversation::participantType($user);
    $participantId = Conversation::participantKey($user);
    $conversationId = resolve(ConversationStore::class)->storeConversation($participantType, $participantId, 'Which role fits me?');

    $messageIds = [];

    for ($number = 1; $number <= $count; $number++) {
        $messageId = sprintf('00000000-0000-7000-8000-%012d', $number);

        ConversationMessage::query()->create([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'agent' => CareerCopilot::class,
            'role' => $number % 2 === 1 ? 'user' : 'assistant',
            'content' => sprintf('Note %02d', $number),
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $messageIds[] = $messageId;
    }

    return [$conversationId, $messageIds];
}

beforeEach(function () {
    config(['ai-routing.batch_daily_budget_usd' => null, 'ai-routing.global_daily_budget_usd' => null]);
});

test('it folds the messages older than the window into the summary', function () {
    config(['ai-routing.copilot.summary_batch' => 4]);
    ConversationSummarizer::fake(['Earlier talk.']);
    $user = User::factory()->onboarded()->create();
    [$conversationId, $messageIds] = storedTurns($user, 14);

    (new SummarizeConversation($conversationId, $user->getKey()))->handle(app(DailyBudget::class));

    ConversationSummarizer::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Note 01')
        && str_contains($prompt->prompt, 'Note 04')
        && ! str_contains($prompt->prompt, 'Note 05'));
    expect(Conversation::query()->find($conversationId)->getAttribute('summary_through'))->toBe($messageIds[3]);
});

test('a later run extends the earlier summary', function () {
    config(['ai-routing.copilot.summary_batch' => 2]);
    ConversationSummarizer::fake(['Extended talk.']);
    $user = User::factory()->onboarded()->create();
    [$conversationId, $messageIds] = storedTurns($user, 16);
    Conversation::query()->whereKey($conversationId)->update([
        'summary' => 'Earlier summary.',
        'summary_through' => $messageIds[3],
    ]);

    (new SummarizeConversation($conversationId, $user->getKey()))->handle(app(DailyBudget::class));

    ConversationSummarizer::assertPrompted(fn (AgentPrompt $prompt): bool => str_starts_with($prompt->prompt, "Earlier summary.\n\n")
        && str_contains($prompt->prompt, 'Note 05')
        && ! str_contains($prompt->prompt, 'Note 07'));
});

test('it waits until a batch of messages is past the window', function () {
    ConversationSummarizer::fake();
    $user = User::factory()->onboarded()->create();
    [$conversationId] = storedTurns($user, 18);

    (new SummarizeConversation($conversationId, $user->getKey()))->handle(app(DailyBudget::class));

    ConversationSummarizer::assertNeverPrompted();
    expect(Conversation::query()->find($conversationId)->getAttribute('summary'))->toBeNull();
});

test('it does nothing when summarize is off', function () {
    config(['ai-routing.copilot.summarize' => false]);
    ConversationSummarizer::fake();
    $user = User::factory()->onboarded()->create();
    [$conversationId] = storedTurns($user, 14);

    (new SummarizeConversation($conversationId, $user->getKey()))->handle(app(DailyBudget::class));

    ConversationSummarizer::assertNeverPrompted();
});
