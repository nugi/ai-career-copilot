<?php

namespace App\Http\Controllers;

use App\Ai\Agents\CareerCopilot;
use App\Http\Requests\AskCopilotRequest;
use App\Jobs\SummarizeConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class CopilotController extends Controller
{
    /**
     * The most conversations that the page lists.
     */
    private const LIST_LIMIT = 50;

    /**
     * Show the copilot page with the user's conversations, and the open conversation when one is given.
     *
     * @throws NotFoundHttpException when the conversation is not the user's
     */
    public function show(Request $request, ?string $conversation = null): Response
    {
        $user = $request->user();

        return Inertia::render('copilot', [
            'conversations' => $this->conversationsOf($user)
                ->map(fn (Conversation $copilotConversation): array => [
                    'id' => $copilotConversation->getKey(),
                    'title' => $copilotConversation->getAttribute('title'),
                    'updated_at' => $copilotConversation->getAttribute('updated_at'),
                ])
                ->values()
                ->all(),
            'conversation' => $conversation === null ? null : $this->openConversation($user, $conversation),
        ]);
    }

    /**
     * Stream the copilot's answer to the user's message as server-sent events.
     *
     * A new message starts a conversation, and the first event carries its ID. Tool calls become status events, and tool results
     * stay on the server. Any failure after the headers are sent becomes one error event instead of a broken body, and the exception
     * is still reported.
     *
     * @throws NotFoundHttpException when the conversation is not the user's
     */
    public function stream(AskCopilotRequest $request): StreamedResponse
    {
        $user = $request->user();
        $message = $request->validated('message');
        $conversationId = $request->validated('conversation_id');

        if ($conversationId === null) {
            $conversationId = resolve(ConversationStore::class)->storeConversation(
                Conversation::participantType($user),
                Conversation::participantKey($user),
                Str::limit($message, 50, preserveWords: true),
            );
        } else {
            abort_unless($this->ownsConversation($user, $conversationId), 404);
        }

        $events = CareerCopilot::make(user: $user)->continue($conversationId, as: $user)->stream($message);

        return response()->stream(function () use ($user, $conversationId, $events) {
            yield 'data: '.json_encode(['type' => 'conversation', 'id' => $conversationId])."\n\n";

            $completed = false;

            try {
                foreach ($events as $event) {
                    $payload = $this->payloadOf($event);

                    if ($payload !== null) {
                        yield "data: {$payload}\n\n";
                    }
                }

                $completed = true;
            } catch (Throwable $exception) {
                report($exception);

                yield 'data: '.json_encode([
                    'type' => 'error',
                    'message' => __('The copilot is unavailable. Try again in a minute.'),
                ])."\n\n";
            }

            if ($completed && config('ai-routing.copilot.summarize')) {
                SummarizeConversation::dispatch($conversationId, $user->getKey());
            }

            yield "data: [DONE]\n\n";
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Get the user's copilot conversations, newest first.
     *
     * A conversation counts when one of its messages was sent to the copilot, so a conversation that failed before its first message is not listed.
     *
     * @return Collection<int, Conversation>
     */
    private function conversationsOf(User $user): Collection
    {
        return Conversation::query()
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user))
            ->whereHas('messages', fn (Builder $messages): Builder => $messages->where('agent', CareerCopilot::class))
            ->latest('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'title', 'updated_at']);
    }

    /**
     * Get the open conversation with its user and assistant text, and without its tool calls.
     *
     * @return array<string, mixed>
     */
    private function openConversation(User $user, string $conversationId): array
    {
        abort_unless($this->ownsConversation($user, $conversationId), 404);

        $conversation = Conversation::query()->findOrFail($conversationId);

        $messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['role', 'content'])
            ->map(fn (ConversationMessage $message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->values()
            ->all();

        return [
            'id' => $conversation->getKey(),
            'title' => $conversation->getAttribute('title'),
            'messages' => $messages,
        ];
    }

    /**
     * Determine whether the conversation was started by the user.
     */
    private function ownsConversation(User $user, string $conversationId): bool
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->where('participant_type', Conversation::participantType($user))
            ->where('participant_id', Conversation::participantKey($user))
            ->exists();
    }

    /**
     * Get the server-sent event payload of an agent event, or null when the browser must not receive the event.
     */
    private function payloadOf(mixed $event): ?string
    {
        return match (true) {
            $event instanceof ToolCall => json_encode(['type' => 'status', 'label' => $this->statusLabel($event->toolCall->name)], JSON_THROW_ON_ERROR),
            $event instanceof ToolResult => null,
            default => (string) $event,
        };
    }

    /**
     * Get the text that the browser shows while the named tool runs.
     */
    private function statusLabel(string $tool): string
    {
        return match ($tool) {
            'SearchJobs' => __('Searching jobs…'),
            'GetUserProfile' => __('Reading your profile…'),
            'ScoreFit' => __('Scoring fit…'),
            default => __('Working…'),
        };
    }
}
