<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversation_mappings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chatwoot_conversation_id')->unique();
            $table->bigInteger('krayin_lead_id');
            $table->string('status', 50)->default('open');
            $table->timestamps();

            // Performance tracking fields (NEW in v2.1)
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
        });

        // Create performance-optimized indexes
        DB::statement('CREATE UNIQUE INDEX idx_conversation_mappings_chatwoot_id ON conversation_mappings(chatwoot_conversation_id)');

        DB::statement('CREATE INDEX idx_conversation_mappings_krayin_lead_id ON conversation_mappings(krayin_lead_id)');

        // Compound indexes for filtered queries (CRITICAL FOR PERFORMANCE)
        DB::statement('CREATE INDEX idx_conversation_mappings_lead_status ON conversation_mappings(krayin_lead_id, status)');

        DB::statement('CREATE INDEX idx_conversation_mappings_lead_updated ON conversation_mappings(krayin_lead_id, updated_at DESC)');

        DB::statement('CREATE INDEX idx_conversation_mappings_status_updated ON conversation_mappings(status, updated_at DESC)');

        // Index for open conversations (covering index)
        DB::statement('CREATE INDEX idx_conversation_mappings_open_conversations ON conversation_mappings(krayin_lead_id, updated_at DESC) WHERE status = \'open\'');

        // Add table and column comments
        DB::statement('COMMENT ON TABLE conversation_mappings IS \'Maps Chatwoot conversations to Krayin leads\'');
        DB::statement('COMMENT ON COLUMN conversation_mappings.status IS \'Conversation status: open, resolved, pending, snoozed\'');
        DB::statement('COMMENT ON COLUMN conversation_mappings.message_count IS \'Cached count of messages (updated via trigger)\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_mappings');
    }
};
