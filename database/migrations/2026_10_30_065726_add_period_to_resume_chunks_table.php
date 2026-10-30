<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resume_chunks', function (Blueprint $table): void {
            $table->string('period_start', 7)->nullable()->after('section');
            $table->string('period_end', 7)->nullable()->after('period_start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resume_chunks', function (Blueprint $table): void {
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
