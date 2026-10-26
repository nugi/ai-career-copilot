<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allowed values of ai_usages.feature, with the new skill extraction task.
     *
     * The values are fixed here on purpose: a migration must not change when an enum changes later.
     *
     * @var list<string>
     */
    private const FEATURES = [
        'fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding', 'resume_parsing', 'skill_extraction',
    ];

    /**
     * Add the saved extraction of the posting text and the hash and prompt version that it is for (ADR 0024).
     *
     * The saved result is one answer of the JobSkillExtractor agent, so a repeat run on the same text pays nothing.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->jsonb('extracted_skills')->nullable();
            $table->string('extracted_seniority')->nullable();
            $table->unsignedSmallInteger('years_required')->nullable();
            $table->char('skills_text_hash', 64)->nullable();
            $table->string('skills_prompt_version')->nullable();
            $table->timestamp('skills_extracted_at')->nullable();
            $table->timestamp('skills_failed_at')->nullable();

            $table->index('text_hash');
        });

        DB::statement("alter table job_postings add constraint job_postings_extracted_seniority_check check (extracted_seniority in ('junior', 'mid', 'senior', 'lead'))");
        DB::statement('alter table job_postings add constraint job_postings_years_required_check check (years_required between 0 and 40)');

        $allowed = implode(', ', array_map(fn (string $feature): string => "'{$feature}'", self::FEATURES));

        DB::statement('alter table ai_usages drop constraint ai_usages_feature_check');
        DB::statement("alter table ai_usages add constraint ai_usages_feature_check check (feature in ({$allowed}))");
    }

    /**
     * Reverse the migrations. The usage rows of the removed feature go first, or the old constraint would reject them.
     */
    public function down(): void
    {
        DB::table('ai_usages')->where('feature', 'skill_extraction')->delete();

        DB::statement('alter table ai_usages drop constraint ai_usages_feature_check');
        DB::statement("alter table ai_usages add constraint ai_usages_feature_check check (feature in ('fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding', 'resume_parsing'))");

        DB::statement('alter table job_postings drop constraint job_postings_years_required_check');
        DB::statement('alter table job_postings drop constraint job_postings_extracted_seniority_check');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropIndex(['text_hash']);
            $table->dropColumn([
                'extracted_skills', 'extracted_seniority', 'years_required',
                'skills_text_hash', 'skills_prompt_version', 'skills_extracted_at', 'skills_failed_at',
            ]);
        });
    }
};
