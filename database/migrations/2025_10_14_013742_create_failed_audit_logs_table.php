<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('failed_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->json('audit_data');
            $table->text('error');
            $table->timestamp('failed_at');
            $table->integer('attempts')->default(0);
            $table->timestamps();

            $table->index(['failed_at', 'attempts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_audit_logs');
    }
};
