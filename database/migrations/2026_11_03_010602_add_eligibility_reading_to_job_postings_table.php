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
            $table->jsonb('eligible_countries')->default('[]');
            $table->jsonb('eligible_regions')->default('[]');
            $table->text('eligibility_evidence')->nullable();
        });

        DB::statement("alter table job_postings add constraint job_postings_eligible_countries_check check (jsonb_typeof(eligible_countries) = 'array')");
        DB::statement("alter table job_postings add constraint job_postings_eligible_regions_check check (jsonb_typeof(eligible_regions) = 'array')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('alter table job_postings drop constraint job_postings_eligible_countries_check');
        DB::statement('alter table job_postings drop constraint job_postings_eligible_regions_check');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['eligible_countries', 'eligible_regions', 'eligibility_evidence']);
        });
    }
};
