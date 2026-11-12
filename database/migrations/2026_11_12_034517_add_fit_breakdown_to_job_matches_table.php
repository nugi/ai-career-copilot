<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_matches', function (Blueprint $table): void {
            $table->string('scoring_version')->nullable();
            $table->jsonb('breakdown')->default('{}');
            $table->jsonb('inputs')->default('{}');
            $table->jsonb('matched_skills')->default('[]');
            $table->jsonb('missing_skills')->default('[]');
            $table->unsignedInteger('profile_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('job_matches', function (Blueprint $table): void {
            $table->dropColumn(['scoring_version', 'breakdown', 'inputs', 'matched_skills', 'missing_skills', 'profile_version']);
        });
    }
};
