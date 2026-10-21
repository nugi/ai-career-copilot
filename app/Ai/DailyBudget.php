<?php

namespace App\Ai;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Keeps a user's advisor calls inside the daily AI budget.
 *
 * The budget is read from config/ai-routing.php, and every call is measured against the spend of the current UTC day,
 * across every AI feature, so resume parsing still counts towards the sum even though it is never blocked.
 */
class DailyBudget
{
    /**
     * Get what the user has spent on AI calls since the start of the current UTC day, in USD.
     */
    public function spentToday(User $user): float
    {
        return (float) $user->aiUsages()
            ->where('created_at', '>=', Carbon::today('UTC'))
            ->sum('cost_usd');
    }

    /**
     * Determine whether the user has spent the whole daily budget, which stops further advisor calls.
     */
    public function isExceeded(User $user): bool
    {
        $budget = config('ai-routing.daily_budget_usd');

        if ($budget === null) {
            return false;
        }

        return $this->spentToday($user) >= (float) $budget;
    }
}
