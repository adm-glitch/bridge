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
        // Create the main partitioned table
        DB::statement('
            CREATE TABLE activity_mappings (
                id BIGSERIAL,
                chatwoot_message_id BIGINT NOT NULL,
                krayin_activity_id BIGINT NOT NULL,
                conversation_id BIGINT NOT NULL,
                message_type VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) PARTITION BY RANGE (created_at)
        ');

        // Create partitions for current + next 3 months
        $currentMonth = now()->startOfMonth();

        for ($i = 0; $i < 4; $i++) {
            $startDate = $currentMonth->copy()->addMonths($i);
            $endDate = $startDate->copy()->addMonth();
            $partitionName = 'activity_mappings_' . $startDate->format('Y_m');

            DB::statement("
                CREATE TABLE {$partitionName} PARTITION OF activity_mappings
                FOR VALUES FROM ('{$startDate->format('Y-m-d')}') TO ('{$endDate->format('Y-m-d')}')
            ");
        }

        // Create unique index (must include partition key for partitioned tables)
        // Note: This must be done after all partitions are created
        DB::statement('
            CREATE UNIQUE INDEX idx_activity_mappings_chatwoot_message_id 
            ON activity_mappings(chatwoot_message_id, created_at)
        ');

        DB::statement('
            CREATE INDEX idx_activity_mappings_krayin_activity_id 
            ON activity_mappings(krayin_activity_id)
        ');

        DB::statement('
            CREATE INDEX idx_activity_mappings_conversation_id 
            ON activity_mappings(conversation_id)
        ');

        DB::statement('
            CREATE INDEX idx_activity_mappings_created_at 
            ON activity_mappings(created_at DESC)
        ');

        // Compound index for conversation message queries (performance critical)
        DB::statement('
            CREATE INDEX idx_activity_mappings_conversation_created 
            ON activity_mappings(conversation_id, created_at DESC)
        ');

        // Index for message type filtering
        DB::statement('
            CREATE INDEX idx_activity_mappings_message_type 
            ON activity_mappings(message_type, created_at DESC)
        ');

        // Add table and column comments
        DB::statement("
            COMMENT ON TABLE activity_mappings IS 'Maps Chatwoot messages to Krayin activities (partitioned by month)'
        ");

        DB::statement("
            COMMENT ON COLUMN activity_mappings.chatwoot_message_id IS 'Chatwoot message ID (unique, indexed)'
        ");

        DB::statement("
            COMMENT ON COLUMN activity_mappings.krayin_activity_id IS 'Krayin activity ID (indexed)'
        ");

        DB::statement("
            COMMENT ON COLUMN activity_mappings.conversation_id IS 'Chatwoot conversation ID for grouping messages'
        ");

        DB::statement("
            COMMENT ON COLUMN activity_mappings.message_type IS 'Message type: incoming, outgoing, activity'
        ");

        // Create function for automated partition management
        DB::statement('
            CREATE OR REPLACE FUNCTION create_monthly_partitions()
            RETURNS void AS $$
            DECLARE
                start_date DATE;
                end_date DATE;
                partition_name TEXT;
            BEGIN
                -- Create partitions for next 3 months
                FOR i IN 1..3 LOOP
                    start_date := date_trunc(\'month\', CURRENT_DATE + (i || \' month\')::INTERVAL);
                    end_date := date_trunc(\'month\', CURRENT_DATE + ((i + 1) || \' month\')::INTERVAL);
                    partition_name := \'activity_mappings_\' || to_char(start_date, \'YYYY_MM\');
                    
                    -- Create partition if it doesn\'t exist
                    EXECUTE format(
                        \'CREATE TABLE IF NOT EXISTS %I PARTITION OF activity_mappings
                         FOR VALUES FROM (%L) TO (%L)\',
                        partition_name, start_date, end_date
                    );
                    
                    RAISE NOTICE \'Created partition: %\', partition_name;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql
        ');

        // Create function for dropping old partitions (data retention)
        DB::statement('
            CREATE OR REPLACE FUNCTION drop_old_partitions(retention_months INT DEFAULT 12)
            RETURNS void AS $$
            DECLARE
                partition_record RECORD;
                cutoff_date DATE;
            BEGIN
                cutoff_date := date_trunc(\'month\', CURRENT_DATE - (retention_months || \' month\')::INTERVAL);
                
                FOR partition_record IN
                    SELECT tablename FROM pg_tables
                    WHERE schemaname = \'public\'
                    AND tablename LIKE \'activity_mappings_%\'
                    AND tablename < \'activity_mappings_\' || to_char(cutoff_date, \'YYYY_MM\')
                LOOP
                    EXECUTE format(\'DROP TABLE IF EXISTS %I\', partition_record.tablename);
                    RAISE NOTICE \'Dropped old partition: %\', partition_record.tablename;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql
        ');

        // Create trigger function to update conversation message count
        DB::statement('
            CREATE OR REPLACE FUNCTION update_conversation_message_count()
            RETURNS TRIGGER AS $$
            BEGIN
                UPDATE conversation_mappings
                SET message_count = message_count + 1,
                    last_message_at = NEW.created_at
                WHERE chatwoot_conversation_id = NEW.conversation_id;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        ');

        // Create the trigger
        DB::statement('
            CREATE TRIGGER trigger_update_message_count
            AFTER INSERT ON activity_mappings
            FOR EACH ROW
            EXECUTE FUNCTION update_conversation_message_count()
        ');

        // Add check constraint for message_type
        DB::statement("
            ALTER TABLE activity_mappings 
            ADD CONSTRAINT chk_activity_mappings_message_type 
            CHECK (message_type IN ('incoming', 'outgoing', 'activity'))
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop trigger first
        DB::statement('DROP TRIGGER IF EXISTS trigger_update_message_count ON activity_mappings');

        // Drop functions
        DB::statement('DROP FUNCTION IF EXISTS update_conversation_message_count()');
        DB::statement('DROP FUNCTION IF EXISTS drop_old_partitions(INT)');
        DB::statement('DROP FUNCTION IF EXISTS create_monthly_partitions()');

        // Drop all partitions first
        $partitions = DB::select("
            SELECT tablename 
            FROM pg_tables 
            WHERE schemaname = 'public' 
            AND tablename LIKE 'activity_mappings_%'
        ");

        foreach ($partitions as $partition) {
            DB::statement("DROP TABLE IF EXISTS {$partition->tablename}");
        }

        // Drop the main table (this will also drop the unique index)
        Schema::dropIfExists('activity_mappings');
    }
};
