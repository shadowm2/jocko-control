<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('shops')->restrictOnDelete();
            $table->string('name');
            $table->string('app_version', 50)->nullable();
            $table->string('database_schema_version', 50)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->timestamp('last_sync_error_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['status', 'last_seen_at']);
            $table->index('last_sync_at');
            $table->index('last_sync_error_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_devices');
    }
};
