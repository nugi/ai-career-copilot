<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allowed values of ai_usages.feature, with the copilot chat.
     *
     * The values are fixed here on purpose: a migration must not change when an enum changes later.
     *
     * @var list<string>
     */
    private const FEATURES = [
        'fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding', 'resume_parsing', 'skill_extraction', 'reranking', 'fit_explanation', 'relevance_grading', 'copilot', 'conversation_summary',
    ];

    /**
     * Let a usage row record the copilot chat.
     */
    public function up(): void
    {
        $allowed = implode(', ', array_map(fn (string $feature): string => "'{$feature}'", self::FEATURES));

        DB::statement('alter table ai_usages drop constraint ai_usages_feature_check');
        DB::statement("alter table ai_usages add constraint ai_usages_feature_check check (feature in ({$allowed}))");
    }

    /**
     * Remove the copilot's usage rows before restoring the old constraint.
     */
    public function down(): void
    {
        DB::table('ai_usages')->whereIn('feature', ['copilot', 'conversation_summary'])->delete();

        DB::statement('alter table ai_usages drop constraint ai_usages_feature_check');
        DB::statement("alter table ai_usages add constraint ai_usages_feature_check check (feature in ('fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding', 'resume_parsing', 'skill_extraction', 'reranking', 'fit_explanation', 'relevance_grading'))");
    }
};
