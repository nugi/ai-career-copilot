<?php

namespace App\Http\Middleware;

use App\Ai\DailyBudget;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiBudget
{
    /**
     * Stop an advisor call when all users have spent the global daily AI budget, or when the user has spent the whole daily AI budget.
     *
     * The global check runs first and stops the advisor for every user. The checks read the day's spend from the usage rows,
     * so a call is refused before the agent is prompted.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $dailyBudget = app(DailyBudget::class);

        if ($dailyBudget->isGloballyExceeded()) {
            Log::warning('ai.budget.global_exceeded');

            return response()->json([
                'message' => __('The advisor is paused for today. It resets at midnight UTC.'),
                'code' => 'ai_global_budget_exceeded',
            ], 429);
        }

        $user = $request->user();

        if ($user !== null && $dailyBudget->isExceeded($user)) {
            Log::info('ai.budget.exceeded', ['user_id' => $user->getKey()]);

            return response()->json([
                'message' => __('You have used today\'s AI budget. It resets at midnight UTC.'),
                'code' => 'ai_budget_exceeded',
            ], 429);
        }

        return $next($request);
    }
}
