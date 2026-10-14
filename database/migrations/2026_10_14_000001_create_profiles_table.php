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
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('headline')->nullable();
            $table->jsonb('target_roles')->default('[]');
            $table->jsonb('preferred_locations')->default('[]');
            $table->jsonb('work_arrangements')->default('[]');
            $table->unsignedBigInteger('min_salary')->nullable();
            $table->char('salary_currency', 3)->nullable();
            $table->string('salary_period')->nullable();
            $table->timestamps();
        });

        DB::statement("alter table profiles add constraint profiles_salary_currency_check check (salary_currency in ('IDR', 'USD'))");
        DB::statement('alter table profiles add constraint profiles_min_salary_check check (min_salary >= 0)');
        DB::statement('alter table profiles add constraint profiles_salary_unit_check check (min_salary is null or (salary_currency is not null and salary_period is not null))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
