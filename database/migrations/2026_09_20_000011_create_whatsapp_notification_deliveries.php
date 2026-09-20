<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('event', 32);
            $table->string('event_key', 96);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['event', 'event_key'], 'wa_deliveries_event_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_deliveries');
    }
};
