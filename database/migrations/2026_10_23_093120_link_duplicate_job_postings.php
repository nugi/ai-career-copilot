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
            $table->string('normalized_company')->nullable();
            $table->string('normalized_title')->nullable();
            $table->string('location_key')->nullable();
            $table->char('duplicate_key', 64)->nullable()->index();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('job_postings')->nullOnDelete();
            $table->index('duplicate_of_id');
        });

        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create index job_postings_normalized_title_trgm_index on job_postings using gin (normalized_title gin_trgm_ops)');
        DB::statement('alter table job_postings add constraint job_postings_duplicate_of_check check (duplicate_of_id is null or duplicate_of_id <> id)');
    }

    /**
     * Reverse the migrations, leaving the shared extension installed.
     */
    public function down(): void
    {
        DB::statement('alter table job_postings drop constraint job_postings_duplicate_of_check');
        DB::statement('drop index job_postings_normalized_title_trgm_index');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropIndex(['duplicate_of_id']);
            $table->dropConstrainedForeignId('duplicate_of_id');
            $table->dropIndex(['duplicate_key']);
            $table->dropColumn(['duplicate_key', 'location_key', 'normalized_title', 'normalized_company']);
        });
    }
};
