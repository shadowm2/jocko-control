<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignUuid('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained('shop_devices')->restrictOnDelete();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 100);
            $table->uuid('aggregate_id');
            $table->unsignedInteger('base_version')->nullable();
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->dateTime('occurred_at', 3);
            $table->dateTime('received_at', 3);
            $table->timestamps();

            $table->index(['shop_id', 'received_at']);
            $table->index(['device_id', 'received_at']);
            $table->index('received_at');
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index(['shop_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
