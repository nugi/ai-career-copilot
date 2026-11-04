<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allowed values of ai_usages.feature, with the new reranking task.
     *
     * The values are fixed here on purpose: a migration must not change when an enum changes later.
     *
     * @var list<string>
     */
    private const FEATURES = [
        'fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding', 'resume_parsing', 'skill_extraction', 'reranking',
    ];

    /**
     * Let a usage row record a reranking call (ADR 0033).
     */
    public function up(): void
    {
        $allowed = implode(', ', array_map(fn (string $feature): string => "'{$feature}'", self::FEATURES));

        DB::statement('alter table ai_usages drop constraint ai_usages_feature_check');
        DB::statement("alter table ai_usages add constraint ai_usages_feature_check check (feature in ({$allowed}))");
    }

    /**
     * Reverse the migration. The usage rows of the removed feature go first, or the old constraint would reject them.
     */
    public function down(): void
    {
        DB::table('ai_usages')->where('feature', 'reranking')->delete();

        DB::statement('alter table ai_usages drop constraint ai_usages_feature_check');
        DB::statement("alter table ai_usages add constraint ai_usages_feature_check check (feature in ('fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding', 'resume_parsing', 'skill_extraction'))");
    }
};
