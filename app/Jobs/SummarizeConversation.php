<?php

namespace App\Jobs;

use App\Ai\Agents\ConversationSummarizer;
use App\Ai\DailyBudget;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;

/**
 * Fold the copilot messages older than the summary window into the conversation's summary, in the background.
 *
 * The job keeps the newest copilot.summary_window messages out of the summary, so the copilot still reads them in full. A
 * failed run changes nothing: the summary and summary_through keep their old values, and the exception is reported by the queue.
 * The unique lock lasts longer than the timeout, so a second run cannot summarize the same messages at the same time.
 */
#[UniqueFor(300)]
class SummarizeConversation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of attempts. A failed call is not retried, so one summary never costs more than one call.
     */
    public int $tries = 1;

    /**
     * The seconds that the job may run: one summarizer call with its 60-second timeout, plus a margin.
     */
    public int $timeout = 90;

    /**
     * The longest tool result, in characters, that the summarizer reads.
     */
    private const TOOL_RESULT_CHARACTERS = 2000;

    /**
     * Create a new job instance. AppServiceProvider routes it to the AI batch queue, which the batch budget limits.
     */
    public function __construct(public string $conversationId, public int $userId) {}

    /**
     * Summarize the messages older than the window, unless summaries are off or the batch budget is spent.
     */
    public function handle(DailyBudget $budget): void
    {
        if (! config('ai-routing.copilot.summarize')) {
            return;
        }

        if ($budget->isBatchExceeded()) {
            Log::info('copilot.summary.skipped_budget', ['conversation_id' => $this->conversationId]);

            return;
        }

        $conversation = Conversation::query()->find($this->conversationId);
        $user = User::query()->find($this->userId);

        if ($conversation === null || $user === null) {
            return;
        }

        $through = $conversation->getAttribute('summary_through');

        $pending = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->when($through !== null, fn (Builder $messages): Builder => $messages->where('id', '>', $through))
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'steps']);

        $window = (int) config('ai-routing.copilot.summary_window');

        if ($pending->count() < $window + max(1, (int) config('ai-routing.copilot.summary_batch'))) {
            return;
        }

        $toSummarize = $pending->take($pending->count() - $window);

        $response = (new ConversationSummarizer($user))->prompt(
            $this->transcript($toSummarize, $conversation->getAttribute('summary')),
        );

        $summary = trim((string) $response->text);

        if ($summary === '') {
            Log::warning('copilot.summary.empty', ['conversation_id' => $this->conversationId]);

            return;
        }

        Conversation::query()->whereKey($this->conversationId)->update([
            'summary' => $summary,
            'summary_through' => $toSummarize->last()->getKey(),
            'summary_version' => ConversationSummarizer::PROMPT_VERSION,
        ]);
    }

    /**
     * Build the summarizer's prompt: the earlier summary first, then each message as "role: content", and the tool results of
     * each message after it.
     *
     * @param  Collection<int, ConversationMessage>  $messages
     */
    private function transcript(Collection $messages, ?string $earlierSummary): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $lines[] = "{$message->role}: {$message->content}";

            $results = $this->toolResultsOf($message);

            if ($results !== []) {
                $lines[] = 'tool results: '.json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $messageLines = implode("\n", $lines);

        return $earlierSummary === null || $earlierSummary === ''
            ? $messageLines
            : $earlierSummary."\n\n".$messageLines;
    }

    /**
     * Get the answered tool calls of a message, each with its tool name and its result cut to the character limit.
     *
     * @return list<array{tool: string, result: string}>
     */
    private function toolResultsOf(ConversationMessage $message): array
    {
        $results = [];

        foreach ($message->steps ?? [] as $step) {
            foreach ($step['tool_calls'] ?? [] as $toolCall) {
                if (! array_key_exists('result', $toolCall)) {
                    continue;
                }

                $result = $toolCall['result'];
                $text = is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                $results[] = [
                    'tool' => (string) ($toolCall['name'] ?? ''),
                    'result' => Str::limit($text, self::TOOL_RESULT_CHARACTERS, ''),
                ];
            }
        }

        return $results;
    }

    /**
     * Get the key that makes the job unique: one queued summary per conversation.
     */
    public function uniqueId(): string
    {
        return $this->conversationId;
    }
}
