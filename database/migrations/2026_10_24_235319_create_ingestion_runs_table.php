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
        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->integer('pages')->default(0);
            $table->integer('fetched')->default(0);
            $table->integer('skipped')->default(0);
            $table->integer('dropped_fields')->default(0);
            $table->integer('created')->default(0);
            $table->integer('updated')->default(0);
            $table->integer('unchanged')->default(0);
            $table->integer('links_changed')->default(0);
            $table->boolean('stopped_early')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index(['source', 'started_at']);
        });

        DB::statement("alter table ingestion_runs add constraint ingestion_runs_status_check check (status in ('running', 'succeeded', 'failed', 'rate_limited'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};
