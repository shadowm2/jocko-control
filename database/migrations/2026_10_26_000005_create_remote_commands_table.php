<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained('shop_devices')->restrictOnDelete();
            $table->string('command_type', 50);
            $table->json('payload')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('delivery_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'status', 'expires_at']);
            $table->index(['shop_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_commands');
    }
};
