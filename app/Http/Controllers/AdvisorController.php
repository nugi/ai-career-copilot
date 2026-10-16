<?php

namespace App\Http\Controllers;

use App\Ai\Agents\CareerAdvisor;
use App\Http\Requests\AskAdvisorRequest;
use Illuminate\Http\JsonResponse;
use Laravel\Ai\Exceptions\FailoverableException;

class AdvisorController extends Controller
{
    /**
     * Answer the user's question with the career advisor agent.
     */
    public function __invoke(AskAdvisorRequest $request): JsonResponse
    {
        $advisor = CareerAdvisor::make(user: $request->user());

        try {
            $response = $advisor->prompt($request->validated('question'));
        } catch (FailoverableException $exception) {
            report($exception);

            return response()->json([
                'message' => __('The advisor is unavailable. Try again in a minute.'),
            ], 503);
        }

        return response()->json([
            'answer' => $response->text,
            'provider' => $response->meta->provider,
            'model' => $response->meta->model,
            'prompt_version' => $advisor->promptVersion(),
        ]);
    }
}
