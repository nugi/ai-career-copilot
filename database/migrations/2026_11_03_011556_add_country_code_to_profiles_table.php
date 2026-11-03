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
        Schema::table('profiles', function (Blueprint $table) {
            $table->char('country_code', 2)->nullable();
        });

        DB::statement("alter table profiles add constraint profiles_country_code_check check (country_code ~ '^[A-Z]{2}$')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('alter table profiles drop constraint profiles_country_code_check');

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('country_code');
        });
    }
};
