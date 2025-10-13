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
        Schema::create('contact_mappings', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chatwoot_contact_id')->unique();
            $table->bigInteger('krayin_lead_id')->nullable();
            $table->bigInteger('krayin_person_id')->nullable();
            $table->timestamps();

            // Add constraint to ensure at least one mapping exists
            $table->rawIndex(
                '(krayin_lead_id IS NOT NULL OR krayin_person_id IS NOT NULL)',
                'chk_contact_mappings_has_mapping'
            );
        });

        // Create performance-optimized indexes using raw SQL for better control
        DB::statement('
            CREATE UNIQUE INDEX idx_contact_mappings_chatwoot_contact_id 
            ON contact_mappings(chatwoot_contact_id)
        ');

        DB::statement('
            CREATE INDEX idx_contact_mappings_krayin_lead_id 
            ON contact_mappings(krayin_lead_id) 
            WHERE krayin_lead_id IS NOT NULL
        ');

        DB::statement('
            CREATE INDEX idx_contact_mappings_krayin_person_id 
            ON contact_mappings(krayin_person_id) 
            WHERE krayin_person_id IS NOT NULL
        ');

        // Compound index for bi-directional lookups
        DB::statement('
            CREATE INDEX idx_contact_mappings_lead_contact 
            ON contact_mappings(krayin_lead_id, chatwoot_contact_id) 
            WHERE krayin_lead_id IS NOT NULL
        ');

        // Add table and column comments for documentation
        DB::statement("COMMENT ON TABLE contact_mappings IS 'Maps Chatwoot contacts to Krayin leads and persons'");
        DB::statement("COMMENT ON COLUMN contact_mappings.chatwoot_contact_id IS 'Chatwoot contact ID (unique, indexed)'");
        DB::statement("COMMENT ON COLUMN contact_mappings.krayin_lead_id IS 'Krayin lead ID (nullable for existing customers)'");
        DB::statement("COMMENT ON COLUMN contact_mappings.krayin_person_id IS 'Krayin person ID (nullable for new leads)'");

        // Add the constraint using raw SQL since Laravel doesn't support complex CHECK constraints
        DB::statement('
            ALTER TABLE contact_mappings 
            ADD CONSTRAINT chk_contact_mappings_has_mapping 
            CHECK (krayin_lead_id IS NOT NULL OR krayin_person_id IS NOT NULL)
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_mappings');
    }
};
