<?php

namespace App\Http\Middleware;

use App\Ai\DailyBudget;
use App\Enums\AiFeature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiBudget
{
    /**
     * Stop an AI call when the budgets are spent, in this order: the global budget, the new account reserve, then the user's budget.
     *
     * The feature comes from the middleware parameter, as in ai.budget:copilot, and it is the chat feature when no parameter is given.
     * The checks read the day's spend from the usage rows, so a call is refused before the agent is prompted.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature = AiFeature::Chat->value): Response
    {
        $dailyBudget = app(DailyBudget::class);

        if ($dailyBudget->isGloballyExceeded()) {
            Log::warning('ai.budget.global_exceeded');

            return response()->json([
                'message' => __('AI is paused for today. It resets at midnight UTC.'),
                'code' => 'ai_global_budget_exceeded',
            ], 429);
        }

        $user = $request->user();

        if ($user !== null && $dailyBudget->isNewAccount($user) && $dailyBudget->isNewAccountReserveExceeded()) {
            Log::info('ai.budget.new_account_reserve_exceeded', ['user_id' => $user->getKey()]);

            return response()->json([
                'message' => __('AI is busy for new accounts today. It resets at midnight UTC.'),
                'code' => 'ai_new_account_reserve_exceeded',
            ], 429);
        }

        if ($user !== null && $dailyBudget->isExceeded($user, AiFeature::from($feature))) {
            Log::info('ai.budget.exceeded', ['user_id' => $user->getKey()]);

            return response()->json([
                'message' => __('You have used today\'s AI budget. It resets at midnight UTC.'),
                'code' => 'ai_budget_exceeded',
            ], 429);
        }

        return $next($request);
    }
}
