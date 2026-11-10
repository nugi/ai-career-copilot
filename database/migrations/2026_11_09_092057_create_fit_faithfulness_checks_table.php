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
        Schema::create('fit_faithfulness_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('prompt_version');
            $table->string('judge_prompt_version');
            $table->unsignedSmallInteger('claims');
            $table->unsignedSmallInteger('supported');
            $table->unsignedSmallInteger('unsupported');
            $table->unsignedSmallInteger('unjudged');
            $table->decimal('score', 4, 3)->nullable();
            $table->timestamp('created_at');
            $table->index(['prompt_version', 'created_at']);
        });

        DB::statement('alter table fit_faithfulness_checks add constraint fit_faithfulness_checks_counts_check check (supported >= 0 and unsupported >= 0 and unjudged >= 0 and claims = supported + unsupported + unjudged)');
        DB::statement('alter table fit_faithfulness_checks add constraint fit_faithfulness_checks_score_check check (score is null or score between 0 and 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fit_faithfulness_checks');
    }
};
