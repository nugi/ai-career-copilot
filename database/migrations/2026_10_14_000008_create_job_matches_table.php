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
        Schema::create('job_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('resume_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('score');
            $table->jsonb('reasons')->default('[]');
            $table->timestamp('scored_at');
            $table->timestamps();

            $table->unique(['user_id', 'job_posting_id']);
            $table->index(['user_id', 'score']);
        });

        DB::statement('alter table job_matches add constraint job_matches_score_check check (score between 0 and 100)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_matches');
    }
};
