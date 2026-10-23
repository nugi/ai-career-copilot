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
        DB::statement('alter table job_postings drop constraint job_postings_salary_currency_check');
        DB::statement("alter table job_postings add constraint job_postings_salary_currency_check check (salary_currency ~ '^[A-Z]{3}$')");
        DB::statement('alter table job_postings drop constraint job_postings_salary_period_check');
        DB::statement("alter table job_postings add constraint job_postings_salary_period_check check (salary_period in ('hourly', 'daily', 'weekly', 'fortnightly', 'monthly', 'yearly'))");
        DB::statement('alter table job_postings drop constraint job_postings_salary_unit_check');
        DB::statement('alter table job_postings add constraint job_postings_salary_unit_check check ((salary_currency is null and salary_period is null) or salary_min is not null or salary_max is not null)');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->string('employment_type')->nullable()->change();
            $table->unsignedBigInteger('annual_salary_min')->nullable();
            $table->unsignedBigInteger('annual_salary_max')->nullable();
            $table->unsignedSmallInteger('salary_normalization_version')->nullable();
            $table->string('city')->nullable();
            $table->jsonb('country_codes')->default('[]');
            $table->string('region')->nullable();
        });

        DB::statement('alter table job_postings add constraint job_postings_annual_salary_range_check check (annual_salary_min >= 0 and annual_salary_max >= 0 and annual_salary_min <= annual_salary_max)');
        DB::statement("alter table job_postings add constraint job_postings_region_check check (region in ('worldwide', 'americas', 'north_america', 'latam', 'europe', 'emea', 'middle_east', 'africa', 'apac'))");
        DB::statement("alter table job_postings add constraint job_postings_country_codes_check check (jsonb_typeof(country_codes) = 'array')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('alter table job_postings drop constraint job_postings_country_codes_check');
        DB::statement('alter table job_postings drop constraint job_postings_region_check');
        DB::statement('alter table job_postings drop constraint job_postings_annual_salary_range_check');

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn(['region', 'country_codes', 'city', 'salary_normalization_version', 'annual_salary_max', 'annual_salary_min']);
            $table->string('employment_type')->nullable(false)->change();
        });

        DB::statement('alter table job_postings drop constraint job_postings_salary_unit_check');
        DB::statement('alter table job_postings add constraint job_postings_salary_unit_check check ((salary_min is null and salary_max is null) or (salary_currency is not null and salary_period is not null))');
        DB::statement('alter table job_postings drop constraint job_postings_salary_period_check');
        DB::statement("alter table job_postings add constraint job_postings_salary_period_check check (salary_period in ('monthly', 'yearly'))");
        DB::statement('alter table job_postings drop constraint job_postings_salary_currency_check');
        DB::statement("alter table job_postings add constraint job_postings_salary_currency_check check (salary_currency in ('IDR', 'USD'))");
    }
};
