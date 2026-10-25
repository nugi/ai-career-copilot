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
        Schema::table('job_postings', function (Blueprint $table) {
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->char('content_hash', 64)->nullable();
        });

        DB::statement('update job_postings set first_seen_at = created_at, last_seen_at = updated_at');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->timestamp('first_seen_at')->useCurrent()->change();
            $table->timestamp('last_seen_at')->useCurrent()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['first_seen_at', 'last_seen_at', 'content_hash']);
        });
    }
};
