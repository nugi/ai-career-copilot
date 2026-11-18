<?php

namespace App\Ai;

use App\Enums\AiFeature;
use App\Models\AiUsage;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Keeps AI calls inside the daily AI budgets, and batch work inside its own budget.
 *
 * The per-user budget (daily_budget_usd) limits one user's spend on every feature except the copilot, each UTC day. The copilot
 * has its own per-user budget (copilot.daily_budget_usd). The global budget (global_daily_budget_usd) limits the spend of all
 * users together each UTC day, and it counts every AI feature. Resume parsing still counts towards it even though it is never blocked.
 *
 * New accounts may spend only a share of the global budget (new_account_share), so that established accounts keep the rest.
 *
 * The batch budget (batch_daily_budget_usd) limits the spend of the features that run in batch, so that batch work cannot
 * spend the share of the global budget that the copilot needs.
 */
class DailyBudget
{
    /**
     * Get what the user has spent on AI calls of the feature since the start of the current UTC day, in USD.
     *
     * The copilot counts only its own spend. Every other feature counts all the spend that is not the copilot's.
     */
    public function spentToday(User $user, AiFeature $feature = AiFeature::Chat): float
    {
        return (float) $this->featureUsageToday($feature)
            ->where('user_id', $user->getKey())
            ->sum('cost_usd');
    }

    /**
     * Determine whether the user has spent the whole daily budget of the feature, which stops further calls of that feature.
     */
    public function isExceeded(User $user, AiFeature $feature = AiFeature::Chat): bool
    {
        $budget = $feature === AiFeature::Copilot
            ? config('ai-routing.copilot.daily_budget_usd')
            : config('ai-routing.daily_budget_usd');

        if ($budget === null) {
            return false;
        }

        return $this->spentToday($user, $feature) >= (float) $budget;
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
     * Determine whether all users together have spent the global daily budget, which stops the AI calls for every user.
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
     * Determine whether the user is a new account, which is created within new_account_days or has no parsed primary resume.
     */
    public function isNewAccount(User $user): bool
    {
        return $this->newAccounts()->whereKey($user->getKey())->exists();
    }

    /**
     * Get what new accounts have spent on AI calls since the start of the current UTC day, in USD.
     */
    public function spentTodayByNewAccounts(): float
    {
        return (float) AiUsage::query()
            ->where('created_at', '>=', Carbon::today('UTC'))
            ->whereIn('user_id', $this->newAccounts()->select('id'))
            ->sum('cost_usd');
    }

    /**
     * Determine whether new accounts have spent their share of the global daily budget, which stops them but not established accounts.
     */
    public function isNewAccountReserveExceeded(): bool
    {
        $budget = config('ai-routing.global_daily_budget_usd');

        if ($budget === null) {
            return false;
        }

        return $this->spentTodayByNewAccounts() >= (float) config('ai-routing.new_account_share') * (float) $budget;
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

    /**
     * Get the usage rows of today for the feature: the copilot's rows, or every row that is not the copilot's.
     *
     * @return Builder<AiUsage>
     */
    private function featureUsageToday(AiFeature $feature): Builder
    {
        $usage = AiUsage::query()->where('created_at', '>=', Carbon::today('UTC'));

        return $feature === AiFeature::Copilot
            ? $usage->where('feature', AiFeature::Copilot->value)
            : $usage->where('feature', '!=', AiFeature::Copilot->value);
    }

    /**
     * Get the users who are new accounts, as one query that the usage rows can use as a subquery.
     *
     * @return Builder<User>
     */
    private function newAccounts(): Builder
    {
        $days = (int) config('ai-routing.new_account_days');

        return User::query()->where(fn (Builder $query): Builder => $query
            ->where('created_at', '>', now()->subDays($days))
            ->orWhereNotIn('id', Resume::query()
                ->select('user_id')
                ->where('is_primary', true)
                ->whereNotNull('parsed_at')
                ->whereNull('parse_failed_at')));
    }
}
