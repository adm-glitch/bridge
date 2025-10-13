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
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('contact_id')->index();
            $table->bigInteger('chatwoot_contact_id')->nullable();
            $table->bigInteger('krayin_lead_id')->nullable();
            $table->string('consent_type', 50)->comment('data_processing, marketing, health_data');
            $table->string('status', 20)->comment('granted, denied, withdrawn');
            $table->timestamp('granted_at', 0)->nullable();
            $table->timestamp('withdrawn_at', 0)->nullable();
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->text('consent_text');
            $table->string('consent_version', 20);
            $table->timestamp('created_at', 0)->useCurrent();
            $table->timestamp('updated_at', 0)->useCurrent()->useCurrentOnUpdate();

            // Add constraint to ensure status dates are consistent
            $table->rawIndex(
                'contact_id, consent_type',
                'idx_consent_contact_type'
            );
        });

        // Add check constraint for status dates consistency
        DB::statement('
            ALTER TABLE consent_records 
            ADD CONSTRAINT chk_consent_status_dates CHECK (
                (status = \'granted\' AND granted_at IS NOT NULL) OR
                (status = \'withdrawn\' AND withdrawn_at IS NOT NULL) OR
                status = \'denied\'
            )
        ');

        // Performance-optimized indexes

        // Index for chatwoot contact lookups (partial index)
        DB::statement('
            CREATE INDEX idx_consent_chatwoot_contact 
            ON consent_records(chatwoot_contact_id) 
            WHERE chatwoot_contact_id IS NOT NULL
        ');

        // Index for status and consent type filtering
        DB::statement('
            CREATE INDEX idx_consent_status 
            ON consent_records(status, consent_type)
        ');

        // Index for time-based queries (LGPD audit requirements)
        DB::statement('
            CREATE INDEX idx_consent_created_at 
            ON consent_records(created_at DESC)
        ');

        // Most critical index: valid consents (covering index for performance)
        DB::statement('
            CREATE INDEX idx_consent_valid 
            ON consent_records(contact_id, consent_type) 
            WHERE status = \'granted\' AND withdrawn_at IS NULL
        ');

        // Add table comment for LGPD compliance documentation
        DB::statement('
            COMMENT ON TABLE consent_records IS 
            \'LGPD: Tracks all consent given/withdrawn by contacts (5-year retention required)\'
        ');

        // Add column comments for clarity
        DB::statement('
            COMMENT ON COLUMN consent_records.consent_type IS 
            \'Type of consent: data_processing, marketing, health_data\'
        ');

        DB::statement('
            COMMENT ON COLUMN consent_records.status IS 
            \'Consent status: granted, denied, withdrawn\'
        ');

        DB::statement('
            COMMENT ON COLUMN consent_records.ip_address IS 
            \'IP address when consent was given/withdrawn (LGPD audit requirement)\'
        ');

        DB::statement('
            COMMENT ON COLUMN consent_records.consent_version IS 
            \'Version of consent text presented to user\'
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
