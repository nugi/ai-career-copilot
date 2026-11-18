<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Migrations\AiMigration;

return new class extends AiMigration
{
    /**
     * Add the summary of the summarized messages to each conversation.
     *
     * The summary replaces the messages up to summary_through, the ID of the newest summarized message. summary_version is the
     * version of the summarizer prompt that wrote it.
     */
    public function up(): void
    {
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            $table->text('summary')->nullable();
            $table->string('summary_through', 36)->nullable();
            $table->string('summary_version')->nullable();
        });
    }

    /**
     * Remove the summary columns.
     */
    public function down(): void
    {
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_through', 'summary_version']);
        });
    }
};
