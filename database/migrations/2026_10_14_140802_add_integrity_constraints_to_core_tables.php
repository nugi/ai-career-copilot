<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allowed values for each enum-backed column, as {table: {column: values}}.
     * The values are fixed here on purpose: a migration must not change when an enum changes later.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const ENUM_COLUMNS = [
        'job_postings' => [
            'work_arrangement' => ['remote', 'hybrid', 'onsite'],
            'employment_type' => ['full_time', 'part_time', 'contract', 'freelance', 'internship'],
            'seniority' => ['junior', 'mid', 'senior', 'lead'],
            'salary_period' => ['monthly', 'yearly'],
        ],
        'profiles' => [
            'salary_period' => ['monthly', 'yearly'],
        ],
        'resume_chunks' => [
            'section' => ['summary', 'experience', 'education', 'skills', 'projects', 'other'],
        ],
        'user_skills' => [
            'proficiency' => ['beginner', 'intermediate', 'advanced', 'expert'],
        ],
        'ai_usages' => [
            'feature' => ['fit_scoring', 'skill_gap_plan', 'resume_suggestion', 'chat', 'embedding'],
        ],
    ];

    /**
     * Columns that hold counts, sizes or money and must not go negative, as {table: columns}.
     * PostgreSQL has no unsigned types, so Laravel's unsigned modifier does not enforce this.
     *
     * @var array<string, list<string>>
     */
    private const NON_NEGATIVE_COLUMNS = [
        'resumes' => ['file_size'],
        'resume_chunks' => ['position', 'token_count'],
        'ai_usages' => ['input_tokens', 'output_tokens', 'latency_ms', 'cost_usd'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->string('dedup_key')->storedAs("coalesce(external_id, 'url:' || md5(apply_url))");
            $table->unique(['source', 'dedup_key']);
        });

        Schema::table('user_skills', function (Blueprint $table) {
            $table->index(['skill_id', 'user_id']);
        });

        foreach (self::ENUM_COLUMNS as $table => $columns) {
            foreach ($columns as $column => $values) {
                $allowed = implode(', ', array_map(fn (string $value) => "'{$value}'", $values));

                DB::statement("alter table {$table} add constraint {$table}_{$column}_check check ({$column} in ({$allowed}))");
            }
        }

        DB::statement(<<<'SQL'
            alter table profiles add constraint profiles_work_arrangements_check
            check (jsonb_typeof(work_arrangements) = 'array' and work_arrangements <@ '["remote", "hybrid", "onsite"]'::jsonb)
            SQL);

        foreach (self::NON_NEGATIVE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("alter table {$table} add constraint {$table}_{$column}_check check ({$column} >= 0)");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::NON_NEGATIVE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("alter table {$table} drop constraint {$table}_{$column}_check");
            }
        }

        DB::statement('alter table profiles drop constraint profiles_work_arrangements_check');

        foreach (self::ENUM_COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                DB::statement("alter table {$table} drop constraint {$table}_{$column}_check");
            }
        }

        Schema::table('user_skills', function (Blueprint $table) {
            $table->dropIndex(['skill_id', 'user_id']);
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropUnique(['source', 'dedup_key']);
            $table->dropColumn('dedup_key');
            $table->unique(['source', 'external_id']);
        });
    }
};
