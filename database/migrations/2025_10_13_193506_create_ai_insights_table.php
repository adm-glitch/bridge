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
        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('krayin_lead_id')->index();

            // Metrics
            $table->integer('total_conversations')->default(0);
            $table->integer('resolved_conversations')->default(0);
            $table->integer('pending_conversations')->default(0);
            $table->decimal('resolution_rate', 5, 2)->default(0.00);
            $table->integer('average_response_time_minutes')->default(0);
            $table->integer('total_messages')->default(0);
            $table->decimal('average_messages_per_conversation', 5, 2)->default(0.00);
            $table->decimal('performance_score', 3, 1)->default(0.0);
            $table->string('engagement_level', 20)->default('low');
            $table->string('trend', 20)->nullable(); // 'improving', 'stable', 'declining'
            $table->json('suggestions')->nullable();
            $table->timestamp('last_interaction_at', 0)->nullable();

            // Time-series fields
            $table->timestamp('calculated_at', 0)->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('valid_from', 0)->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('valid_to', 0)->nullable();
            $table->boolean('is_current')->default(true);

            $table->timestamp('created_at', 0)->default(DB::raw('CURRENT_TIMESTAMP'));

            // Ensure only one current record per lead
            $table->unique(['krayin_lead_id', 'is_current'], 'unique_current_insight')
                ->where('is_current', true);
        });

        // Performance-optimized indexes
        DB::statement('
            CREATE INDEX idx_ai_insights_lead_current 
            ON ai_insights(krayin_lead_id, is_current) 
            WHERE is_current = TRUE
        ');

        DB::statement('
            CREATE INDEX idx_ai_insights_lead_time 
            ON ai_insights(krayin_lead_id, calculated_at DESC)
        ');

        DB::statement('
            CREATE INDEX idx_ai_insights_score_current 
            ON ai_insights(performance_score DESC, krayin_lead_id) 
            WHERE is_current = TRUE
        ');

        DB::statement('
            CREATE INDEX idx_ai_insights_engagement_current 
            ON ai_insights(engagement_level, performance_score DESC) 
            WHERE is_current = TRUE
        ');

        DB::statement('
            CREATE INDEX idx_ai_insights_trend 
            ON ai_insights(trend, performance_score DESC) 
            WHERE is_current = TRUE
        ');

        // Add table and column comments
        DB::statement("
            COMMENT ON TABLE ai_insights IS 'AI-generated insights with historical tracking (time-series design)'
        ");

        DB::statement("
            COMMENT ON COLUMN ai_insights.performance_score IS 'Performance score 0-10 (10 = excellent)'
        ");

        DB::statement("
            COMMENT ON COLUMN ai_insights.is_current IS 'TRUE for current record, FALSE for historical'
        ");

        DB::statement("
            COMMENT ON COLUMN ai_insights.valid_from IS 'Start of validity period'
        ");

        DB::statement("
            COMMENT ON COLUMN ai_insights.valid_to IS 'End of validity period (NULL for current)'
        ");

        // Create PostgreSQL function for atomic updates
        DB::statement("
            CREATE OR REPLACE FUNCTION update_ai_insights(
                p_lead_id BIGINT,
                p_metrics JSONB
            ) RETURNS BIGINT AS $$
            DECLARE
                v_insight_id BIGINT;
                v_previous_score DECIMAL(3,1);
            BEGIN
                -- Get previous score for trend calculation
                SELECT performance_score INTO v_previous_score
                FROM ai_insights
                WHERE krayin_lead_id = p_lead_id AND is_current = TRUE;
                
                -- Mark previous record as historical
                UPDATE ai_insights
                SET is_current = FALSE,
                    valid_to = CURRENT_TIMESTAMP
                WHERE krayin_lead_id = p_lead_id
                  AND is_current = TRUE;
                
                -- Insert new current record
                INSERT INTO ai_insights (
                    krayin_lead_id,
                    total_conversations,
                    resolved_conversations,
                    pending_conversations,
                    resolution_rate,
                    average_response_time_minutes,
                    total_messages,
                    average_messages_per_conversation,
                    performance_score,
                    engagement_level,
                    trend,
                    suggestions,
                    last_interaction_at,
                    is_current
                ) VALUES (
                    p_lead_id,
                    (p_metrics->>'total_conversations')::INT,
                    (p_metrics->>'resolved_conversations')::INT,
                    (p_metrics->>'pending_conversations')::INT,
                    (p_metrics->>'resolution_rate')::DECIMAL,
                    (p_metrics->>'average_response_time_minutes')::INT,
                    (p_metrics->>'total_messages')::INT,
                    (p_metrics->>'average_messages_per_conversation')::DECIMAL,
                    (p_metrics->>'performance_score')::DECIMAL,
                    p_metrics->>'engagement_level',
                    CASE
                        WHEN v_previous_score IS NULL THEN NULL
                        WHEN (p_metrics->>'performance_score')::DECIMAL > v_previous_score + 0.5 THEN 'improving'
                        WHEN (p_metrics->>'performance_score')::DECIMAL < v_previous_score - 0.5 THEN 'declining'
                        ELSE 'stable'
                    END,
                    p_metrics->'suggestions',
                    (p_metrics->>'last_interaction_at')::TIMESTAMP,
                    TRUE
                ) RETURNING id INTO v_insight_id;
                
                RETURN v_insight_id;
            END;
            $$ LANGUAGE plpgsql;
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the PostgreSQL function
        DB::statement('DROP FUNCTION IF EXISTS update_ai_insights(BIGINT, JSONB)');

        // Drop indexes (will be dropped automatically with table, but explicit for clarity)
        DB::statement('DROP INDEX IF EXISTS idx_ai_insights_lead_current');
        DB::statement('DROP INDEX IF EXISTS idx_ai_insights_lead_time');
        DB::statement('DROP INDEX IF EXISTS idx_ai_insights_score_current');
        DB::statement('DROP INDEX IF EXISTS idx_ai_insights_engagement_current');
        DB::statement('DROP INDEX IF EXISTS idx_ai_insights_trend');

        Schema::dropIfExists('ai_insights');
    }
};
