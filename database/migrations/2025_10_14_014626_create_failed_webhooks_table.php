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
        Schema::create('failed_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->unique();
            $table->string('event_type');
            $table->json('payload');
            $table->text('error');
            $table->timestamp('failed_at');
            $table->integer('attempts')->default(0);
            $table->timestamps();

            $table->index(['event_type', 'failed_at']);
            $table->index(['failed_at', 'attempts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('failed_webhooks');
    }
};
