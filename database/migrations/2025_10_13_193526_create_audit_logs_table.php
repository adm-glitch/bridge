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
        // Create the main partitioned audit_logs table
        DB::statement('
            CREATE TABLE audit_logs (
                id BIGSERIAL,
                user_id BIGINT NULL,
                action VARCHAR(50) NOT NULL,
                model VARCHAR(100) NOT NULL,
                model_id BIGINT NULL,
                changes JSONB NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) PARTITION BY RANGE (created_at)
        ');

        // Create initial partition for current quarter (2025 Q4)
        DB::statement("
            CREATE TABLE audit_logs_2025_q4 PARTITION OF audit_logs
            FOR VALUES FROM ('2025-10-01') TO ('2026-01-01')
        ");

        // Create next quarter partition (2026 Q1)
        DB::statement("
            CREATE TABLE audit_logs_2026_q1 PARTITION OF audit_logs
            FOR VALUES FROM ('2026-01-01') TO ('2026-04-01')
        ");

        // Create performance-optimized indexes
        DB::statement('
            CREATE INDEX idx_audit_logs_user 
            ON audit_logs(user_id, created_at DESC)
        ');

        DB::statement('
            CREATE INDEX idx_audit_logs_model 
            ON audit_logs(model, model_id, created_at DESC)
        ');

        DB::statement('
            CREATE INDEX idx_audit_logs_action 
            ON audit_logs(action, created_at DESC)
        ');

        DB::statement('
            CREATE INDEX idx_audit_logs_created_at 
            ON audit_logs(created_at DESC)
        ');

        // Add table comment for documentation
        DB::statement("
            COMMENT ON TABLE audit_logs IS 'LGPD: Complete audit trail (partitioned by quarter, 5-year retention)'
        ");

        // Add column comments
        DB::statement("
            COMMENT ON COLUMN audit_logs.action IS 'Action performed: create, read, update, delete, export'
        ");

        DB::statement("
            COMMENT ON COLUMN audit_logs.model IS 'Model/table name that was affected'
        ");

        DB::statement("
            COMMENT ON COLUMN audit_logs.changes IS 'JSON object containing before/after values for updates'
        ");

        DB::statement("
            COMMENT ON COLUMN audit_logs.ip_address IS 'IP address of the user performing the action'
        ");

        // Create function to automatically create future partitions
        DB::statement('
            CREATE OR REPLACE FUNCTION create_audit_log_partitions()
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
                    partition_name := \'audit_logs_\' || to_char(start_date, \'YYYY_q\') || \'q\' || extract(quarter from start_date);
                    
                    -- Create partition if it doesn\'t exist
                    EXECUTE format(
                        \'CREATE TABLE IF NOT EXISTS %I PARTITION OF audit_logs
                         FOR VALUES FROM (%L) TO (%L)\',
                        partition_name, start_date, end_date
                    );
                    
                    RAISE NOTICE \'Created audit log partition: %\', partition_name;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql
        ');

        // Create function to drop old partitions (LGPD: 5-year retention)
        DB::statement('
            CREATE OR REPLACE FUNCTION drop_old_audit_partitions(retention_years INT DEFAULT 5)
            RETURNS void AS $$
            DECLARE
                partition_record RECORD;
                cutoff_date DATE;
            BEGIN
                cutoff_date := date_trunc(\'quarter\', CURRENT_DATE - (retention_years || \' year\')::INTERVAL);
                
                FOR partition_record IN
                    SELECT tablename FROM pg_tables
                    WHERE schemaname = \'public\'
                    AND tablename LIKE \'audit_logs_%\'
                    AND tablename < \'audit_logs_\' || to_char(cutoff_date, \'YYYY_q\') || \'q\' || extract(quarter from cutoff_date)
                LOOP
                    EXECUTE format(\'DROP TABLE IF EXISTS %I\', partition_record.tablename);
                    RAISE NOTICE \'Dropped old audit partition: %\', partition_record.tablename;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql
        ');

        // Create helper function for logging audit events
        DB::statement('
            CREATE OR REPLACE FUNCTION log_audit_event(
                p_user_id BIGINT,
                p_action VARCHAR(50),
                p_model VARCHAR(100),
                p_model_id BIGINT,
                p_changes JSONB,
                p_ip_address VARCHAR(45),
                p_user_agent TEXT
            ) RETURNS BIGINT AS $$
            DECLARE
                v_audit_id BIGINT;
            BEGIN
                INSERT INTO audit_logs (
                    user_id,
                    action,
                    model,
                    model_id,
                    changes,
                    ip_address,
                    user_agent
                ) VALUES (
                    p_user_id,
                    p_action,
                    p_model,
                    p_model_id,
                    p_changes,
                    p_ip_address,
                    p_user_agent
                ) RETURNING id INTO v_audit_id;
                
                RETURN v_audit_id;
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
        DB::statement('DROP FUNCTION IF EXISTS log_audit_event(BIGINT, VARCHAR(50), VARCHAR(100), BIGINT, JSONB, VARCHAR(45), TEXT)');
        DB::statement('DROP FUNCTION IF EXISTS drop_old_audit_partitions(INT)');
        DB::statement('DROP FUNCTION IF EXISTS create_audit_log_partitions()');

        // Drop partitions (this will also drop the main table)
        DB::statement('DROP TABLE IF EXISTS audit_logs_2026_q1');
        DB::statement('DROP TABLE IF EXISTS audit_logs_2025_q4');

        // Drop main table
        Schema::dropIfExists('audit_logs');
    }
};
