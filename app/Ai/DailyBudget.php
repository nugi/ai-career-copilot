<?php

namespace App\Ai;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Keeps advisor calls inside the daily AI budgets, and batch work inside its own budget.
 *
 * The per-user budget (daily_budget_usd) limits one user's spend each UTC day. The global budget (global_daily_budget_usd)
 * limits the spend of all users together each UTC day. Both are read from config/ai-routing.php, and both sums count every
 * AI feature, so resume parsing still counts towards them even though it is never blocked.
 *
 * The batch budget (batch_daily_budget_usd) limits the spend of the features that run in batch, so that batch work cannot
 * spend the share of the global budget that the advisor needs.
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

    /**
     * Get what all users have spent on AI calls since the start of the current UTC day, in USD.
     */
    public function spentTodayByEveryone(): float
    {
        return (float) AiUsage::query()
            ->where('created_at', '>=', Carbon::today('UTC'))
            ->sum('cost_usd');
    }

    /**
     * Determine whether all users together have spent the global daily budget, which stops the advisor for every user.
     */
    public function isGloballyExceeded(): bool
    {
        $budget = config('ai-routing.global_daily_budget_usd');

        if ($budget === null) {
            return false;
        }

        return $this->spentTodayByEveryone() >= (float) $budget;
    }

    /**
     * Get what batch features have spent since the start of the current UTC day, in USD.
     */
    public function spentTodayOnBatchWork(): float
    {
        $features = collect(AiFeature::cases())
            ->filter(fn (AiFeature $feature): bool => $feature->runsInBatch())
            ->map(fn (AiFeature $feature): string => $feature->value)
            ->all();

        return (float) AiUsage::query()
            ->where('created_at', '>=', Carbon::today('UTC'))
            ->whereIn('feature', $features)
            ->sum('cost_usd');
    }

    /**
     * Determine whether batch work has spent its own daily budget or the global daily budget, which stops batch work.
     */
    public function isBatchExceeded(): bool
    {
        if ($this->isGloballyExceeded()) {
            return true;
        }

        $budget = config('ai-routing.batch_daily_budget_usd');

        if ($budget === null) {
            return false;
        }

        return $this->spentTodayOnBatchWork() >= (float) $budget;
    }
}
