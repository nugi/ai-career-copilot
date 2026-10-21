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
     * Stop an advisor call when the user has spent the whole daily AI budget.
     *
     * The check reads the day's spend from the usage rows, so a call is refused before the agent is prompted.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && app(DailyBudget::class)->isExceeded($user)) {
            Log::info('ai.budget.exceeded', ['user_id' => $user->getKey()]);

            return response()->json([
                'message' => __('You have used today\'s AI budget. It resets at midnight UTC.'),
                'code' => 'ai_budget_exceeded',
            ], 429);
        }

        return $next($request);
    }
}
