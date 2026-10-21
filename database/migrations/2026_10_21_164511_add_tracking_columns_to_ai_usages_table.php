<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_usages', function (Blueprint $table) {
            $table->uuid('invocation_id')->nullable();
            $table->string('agent', 100);
            $table->string('tier', 16)->nullable();
            $table->string('prompt_version', 16)->nullable();
            $table->string('status', 16)->default('succeeded');

            $table->index(['agent', 'created_at']);
        });

        DB::statement('alter table ai_usages drop constraint ai_usages_feature_check');
        DB::statement("alter table ai_usages add constraint ai_usages_feature_check check (feature in ('fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding', 'resume_parsing'))");
        DB::statement("alter table ai_usages add constraint ai_usages_status_check check (status in ('succeeded', 'failed_over', 'failed'))");
        DB::statement("alter table ai_usages add constraint ai_usages_tier_check check (tier is null or tier in ('cheap', 'strong'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('alter table ai_usages drop constraint ai_usages_tier_check');
        DB::statement('alter table ai_usages drop constraint ai_usages_status_check');
        DB::statement('alter table ai_usages drop constraint ai_usages_feature_check');
        DB::statement("alter table ai_usages add constraint ai_usages_feature_check check (feature in ('fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding'))");

        Schema::table('ai_usages', function (Blueprint $table) {
            $table->dropIndex(['agent', 'created_at']);
            $table->dropColumn(['invocation_id', 'agent', 'tier', 'prompt_version', 'status']);
        });
    }
};
