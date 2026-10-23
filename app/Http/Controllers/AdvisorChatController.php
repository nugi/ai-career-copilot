<?php

namespace App\Http\Controllers;

use App\Ai\Agents\CareerAdvisor;
use App\Http\Requests\AskAdvisorRequest;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdvisorChatController extends Controller
{
    /**
     * Show the chat page that streams the career advisor's answers.
     */
    public function show(): Response
    {
        return Inertia::render('advisor');
    }

    /**
     * Stream the career advisor's answer to the user's question as server-sent events.
     *
     * Any failure after the headers are sent becomes one error event instead of a broken body, and
     * the exception is still reported.
     */
    public function stream(AskAdvisorRequest $request): StreamedResponse
    {
        $advisor = CareerAdvisor::make(user: $request->user());

        $events = $advisor->stream($request->validated('question'));

        return response()->stream(function () use ($events) {
            try {
                foreach ($events as $event) {
                    yield "data: {$event}\n\n";
                }
            } catch (Throwable $exception) {
                report($exception);

                yield 'data: '.json_encode([
                    'type' => 'error',
                    'message' => __('The advisor is unavailable. Try again in a minute.'),
                ])."\n\n";
            }

            yield "data: [DONE]\n\n";
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
