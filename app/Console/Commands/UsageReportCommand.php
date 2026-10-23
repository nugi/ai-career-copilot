<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;

#[Signature('usage:report {--days=7 : The number of days to report}')]
#[Description('Show AI calls, tokens and estimated cost per tier, agent and model.')]
class UsageReportCommand extends Command
{
    /**
     * Print the calls, the tokens, the cost and the average latency per tier, agent, provider and model, then the cost per tier.
     *
     * The report reads the usage rows only. It never prints a prompt, an answer or an exception.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('The --days option must be 1 or more.');

            return self::FAILURE;
        }

        $since = now()->subDays($days);

        $rows = DB::table('ai_usages')
            ->where('created_at', '>=', $since)
            ->selectRaw(<<<'SQL'
                tier,
                agent,
                provider,
                model,
                count(*) as calls,
                count(*) filter (where status in ('failed_over', 'failed')) as failed,
                count(*) filter (where tokens_estimated) as estimated,
                coalesce(sum(input_tokens), 0) as input_tokens,
                coalesce(sum(output_tokens), 0) as output_tokens,
                coalesce(sum(cost_usd), 0) as cost_usd,
                coalesce(round(avg(latency_ms)), 0) as avg_latency_ms
                SQL)
            ->groupBy('tier', 'agent', 'provider', 'model')
            ->orderBy('tier')
            ->orderBy('agent')
            ->orderBy('provider')
            ->orderBy('model')
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No AI usage in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->table(
            ['Tier', 'Agent', 'Provider', 'Model', 'Calls', 'Failed', 'Estimated', 'Input tokens', 'Output tokens', 'Cost (USD)', 'Avg latency (ms)'],
            $rows->map(fn (stdClass $row): array => [
                $row->tier ?? '-',
                $row->agent,
                $row->provider,
                $row->model,
                (int) $row->calls,
                (int) $row->failed,
                (int) $row->estimated,
                (int) $row->input_tokens,
                (int) $row->output_tokens,
                number_format((float) $row->cost_usd, 4),
                (int) $row->avg_latency_ms,
            ])->all(),
        );

        $totals = DB::table('ai_usages')
            ->where('created_at', '>=', $since)
            ->selectRaw('tier, count(*) as calls, coalesce(sum(cost_usd), 0) as cost_usd')
            ->groupBy('tier')
            ->orderBy('tier')
            ->get();

        $tierRows = $totals->map(fn (stdClass $row): array => [
            $row->tier ?? '-',
            (int) $row->calls,
            number_format((float) $row->cost_usd, 4),
        ]);

        $tierRows->push([
            'Total',
            (int) $totals->sum(fn (stdClass $row): int => (int) $row->calls),
            number_format((float) $totals->sum(fn (stdClass $row): float => (float) $row->cost_usd), 4),
        ]);

        $this->table(['Tier', 'Calls', 'Cost (USD)'], $tierRows->all());

        return self::SUCCESS;
    }
}
