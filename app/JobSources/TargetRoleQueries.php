<?php

namespace App\JobSources;

use Illuminate\Support\Facades\DB;

/**
 * Builds source search queries from the most common target roles on profiles.
 */
final class TargetRoleQueries
{
    /**
     * Get cleaned roles by frequency, then alphabetically, or the default queries.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $roles = DB::table('profiles')
            ->selectRaw('jsonb_array_elements_text(target_roles) as role, count(*) as occurrences')
            ->groupBy('role')
            ->get();

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($roles as $row) {
            $role = mb_strtolower((string) $row->role);
            $role = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $role) ?? '';
            $role = trim(preg_replace('/ +/', ' ', $role) ?? '');

            if ($role === '' || mb_strlen($role) > 60) {
                continue;
            }

            $counts[$role] = ($counts[$role] ?? 0) + (int) $row->occurrences;
        }

        $limit = config()->integer('job-sources.max_queries');

        if ($counts === []) {
            /** @var list<string> $defaults */
            $defaults = config()->array('job-sources.default_queries');

            return array_slice($defaults, 0, $limit);
        }

        $queries = array_map(strval(...), array_keys($counts));
        usort($queries, fn (string $left, string $right): int => ($counts[$right] <=> $counts[$left]) ?: strcmp($left, $right));

        return array_slice($queries, 0, $limit);
    }
}
