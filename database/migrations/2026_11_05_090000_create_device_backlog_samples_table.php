<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many changes a device had waiting to be sent, sampled from its heartbeats.
        Schema::create('device_backlog_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('device_id')->constrained('shop_devices')->cascadeOnDelete();
            $table->unsignedInteger('pending_events');
            $table->unsignedInteger('oldest_pending_seconds')->nullable();
            $table->timestamp('sampled_at');

            $table->index(['device_id', 'sampled_at']);
            $table->index('sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_backlog_samples');
    }
};
