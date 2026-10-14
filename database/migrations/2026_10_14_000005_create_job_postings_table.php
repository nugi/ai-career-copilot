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
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_id')->nullable();
            $table->string('title');
            $table->string('company_name');
            $table->text('description');
            $table->string('location')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('work_arrangement');
            $table->string('employment_type');
            $table->string('seniority')->nullable();
            $table->unsignedBigInteger('salary_min')->nullable();
            $table->unsignedBigInteger('salary_max')->nullable();
            $table->char('salary_currency', 3)->nullable();
            $table->string('salary_period')->nullable();
            $table->string('apply_url');
            $table->timestamp('posted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index('posted_at');
            $table->index(['work_arrangement', 'posted_at']);
            $table->index(['salary_currency', 'salary_max']);
        });

        DB::statement("alter table job_postings add constraint job_postings_salary_currency_check check (salary_currency in ('IDR', 'USD'))");
        DB::statement('alter table job_postings add constraint job_postings_salary_range_check check (salary_min >= 0 and salary_max >= 0 and salary_min <= salary_max)');
        DB::statement('alter table job_postings add constraint job_postings_salary_unit_check check ((salary_min is null and salary_max is null) or (salary_currency is not null and salary_period is not null))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
