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
        Schema::table('user_skills', function (Blueprint $table) {
            $table->string('source')->default('profile');
        });

        DB::statement('alter table user_skills alter column source drop default');
        DB::statement('alter table user_skills alter column proficiency drop not null');
        DB::statement("alter table user_skills add constraint user_skills_source_check check (source in ('profile', 'resume'))");
        DB::statement("alter table user_skills add constraint user_skills_proficiency_required_check check (source <> 'profile' or proficiency is not null)");

        Schema::table('resumes', function (Blueprint $table) {
            $table->jsonb('parsed_data')->nullable();
            $table->string('prompt_version')->nullable();
            $table->timestamp('parse_failed_at')->nullable();
            $table->jsonb('parse_errors')->nullable();
        });

        DB::table('resumes')->update(['parsed_at' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropColumn(['parsed_data', 'prompt_version', 'parse_failed_at', 'parse_errors']);
        });

        DB::table('user_skills')->whereNull('proficiency')->delete();

        DB::statement('alter table user_skills drop constraint user_skills_proficiency_required_check');
        DB::statement('alter table user_skills drop constraint user_skills_source_check');
        DB::statement('alter table user_skills alter column proficiency set not null');

        Schema::table('user_skills', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
