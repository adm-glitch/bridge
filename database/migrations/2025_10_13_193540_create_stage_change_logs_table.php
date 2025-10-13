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
        // Create the main partitioned stage_change_logs table
        DB::statement('
            CREATE TABLE stage_change_logs (
                id BIGSERIAL,
                krayin_lead_id BIGINT NOT NULL,
                previous_stage VARCHAR(100) NULL,
                new_stage VARCHAR(100) NOT NULL,
                trigger_source VARCHAR(50) NOT NULL,
                chatwoot_conversation_id BIGINT NULL,
                chatwoot_status VARCHAR(50) NULL,
                changed_by_user_id BIGINT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) PARTITION BY RANGE (created_at)
        ');

        // Create initial partition for current quarter (2025 Q4)
        DB::statement("
            CREATE TABLE stage_change_logs_2025_q4 PARTITION OF stage_change_logs
            FOR VALUES FROM ('2025-10-01') TO ('2026-01-01')
        ");

        // Create next quarter partition (2026 Q1)
        DB::statement("
            CREATE TABLE stage_change_logs_2026_q1 PARTITION OF stage_change_logs
            FOR VALUES FROM ('2026-01-01') TO ('2026-04-01')
        ");

        // Create performance-optimized indexes
        DB::statement('
            CREATE INDEX idx_stage_change_logs_krayin_lead_id 
            ON stage_change_logs(krayin_lead_id, created_at DESC)
        ');

        DB::statement('
            CREATE INDEX idx_stage_change_logs_created_at 
            ON stage_change_logs(created_at DESC)
        ');

        DB::statement('
            CREATE INDEX idx_stage_change_logs_trigger_source 
            ON stage_change_logs(trigger_source, created_at DESC)
        ');

        // Index for conversation-based queries
        DB::statement('
            CREATE INDEX idx_stage_change_logs_conversation 
            ON stage_change_logs(chatwoot_conversation_id, created_at DESC)
            WHERE chatwoot_conversation_id IS NOT NULL
        ');

        // Index for user-based queries
        DB::statement('
            CREATE INDEX idx_stage_change_logs_user 
            ON stage_change_logs(changed_by_user_id, created_at DESC)
            WHERE changed_by_user_id IS NOT NULL
        ');

        // Add table and column comments
        DB::statement("
            COMMENT ON TABLE stage_change_logs IS 'Audit log for lead stage changes (partitioned by quarter, 5-year retention)'
        ");

        DB::statement("
            COMMENT ON COLUMN stage_change_logs.trigger_source IS 'Source: webhook, manual, automation'
        ");

        DB::statement("
            COMMENT ON COLUMN stage_change_logs.previous_stage IS 'Previous stage name (NULL for new leads)'
        ");

        DB::statement("
            COMMENT ON COLUMN stage_change_logs.new_stage IS 'New stage name after change'
        ");

        DB::statement("
            COMMENT ON COLUMN stage_change_logs.chatwoot_conversation_id IS 'Related Chatwoot conversation (if triggered by chat)'
        ");

        DB::statement("
            COMMENT ON COLUMN stage_change_logs.changed_by_user_id IS 'User who made the change (NULL for automated changes)'
        ");

        // Add check constraint for trigger_source
        DB::statement("
            ALTER TABLE stage_change_logs 
            ADD CONSTRAINT chk_stage_change_trigger_source 
            CHECK (trigger_source IN ('webhook', 'manual', 'automation'))
        ");

        // Create function to automatically create future partitions
        DB::statement('
            CREATE OR REPLACE FUNCTION create_stage_change_partitions()
            RETURNS void AS $$
            DECLARE
                start_date DATE;
                end_date DATE;
                partition_name TEXT;
            BEGIN
                -- Create partitions for next 4 quarters
                FOR i IN 1..4 LOOP
                    start_date := date_trunc(\'quarter\', CURRENT_DATE + (i || \' quarter\')::INTERVAL);
                    end_date := date_trunc(\'quarter\', CURRENT_DATE + ((i + 1) || \' quarter\')::INTERVAL);
                    partition_name := \'stage_change_logs_\' || to_char(start_date, \'YYYY_q\') || \'q\' || extract(quarter from start_date);
                    
                    -- Create partition if it doesn\'t exist
                    EXECUTE format(
                        \'CREATE TABLE IF NOT EXISTS %I PARTITION OF stage_change_logs
                         FOR VALUES FROM (%L) TO (%L)\',
                        partition_name, start_date, end_date
                    );
                    
                    RAISE NOTICE \'Created stage change log partition: %\', partition_name;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql
        ');

        // Create function to drop old partitions (LGPD: 5-year retention)
        DB::statement('
            CREATE OR REPLACE FUNCTION drop_old_stage_change_partitions(retention_years INT DEFAULT 5)
            RETURNS void AS $$
            DECLARE
                partition_record RECORD;
                cutoff_date DATE;
            BEGIN
                cutoff_date := date_trunc(\'quarter\', CURRENT_DATE - (retention_years || \' year\')::INTERVAL);
                
                FOR partition_record IN
                    SELECT tablename FROM pg_tables
                    WHERE schemaname = \'public\'
                    AND tablename LIKE \'stage_change_logs_%\'
                    AND tablename < \'stage_change_logs_\' || to_char(cutoff_date, \'YYYY_q\') || \'q\' || extract(quarter from cutoff_date)
                LOOP
                    EXECUTE format(\'DROP TABLE IF EXISTS %I\', partition_record.tablename);
                    RAISE NOTICE \'Dropped old stage change partition: %\', partition_record.tablename;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql
        ');

        // Create helper function for logging stage changes
        DB::statement('
            CREATE OR REPLACE FUNCTION log_stage_change(
                p_krayin_lead_id BIGINT,
                p_previous_stage VARCHAR(100),
                p_new_stage VARCHAR(100),
                p_trigger_source VARCHAR(50),
                p_chatwoot_conversation_id BIGINT DEFAULT NULL,
                p_chatwoot_status VARCHAR(50) DEFAULT NULL,
                p_changed_by_user_id BIGINT DEFAULT NULL
            ) RETURNS BIGINT AS $$
            DECLARE
                v_log_id BIGINT;
            BEGIN
                INSERT INTO stage_change_logs (
                    krayin_lead_id,
                    previous_stage,
                    new_stage,
                    trigger_source,
                    chatwoot_conversation_id,
                    chatwoot_status,
                    changed_by_user_id
                ) VALUES (
                    p_krayin_lead_id,
                    p_previous_stage,
                    p_new_stage,
                    p_trigger_source,
                    p_chatwoot_conversation_id,
                    p_chatwoot_status,
                    p_changed_by_user_id
                ) RETURNING id INTO v_log_id;
                
                RETURN v_log_id;
            END;
            $$ LANGUAGE plpgsql
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop functions first
        DB::statement('DROP FUNCTION IF EXISTS log_stage_change(BIGINT, VARCHAR(100), VARCHAR(100), VARCHAR(50), BIGINT, VARCHAR(50), BIGINT)');
        DB::statement('DROP FUNCTION IF EXISTS drop_old_stage_change_partitions(INT)');
        DB::statement('DROP FUNCTION IF EXISTS create_stage_change_partitions()');

        // Drop partitions (this will also drop the main table)
        DB::statement('DROP TABLE IF EXISTS stage_change_logs_2026_q1');
        DB::statement('DROP TABLE IF EXISTS stage_change_logs_2025_q4');

        // Drop main table
        Schema::dropIfExists('stage_change_logs');
    }
};
