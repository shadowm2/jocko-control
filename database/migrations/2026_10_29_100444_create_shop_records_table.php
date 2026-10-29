<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_records', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('shop_id')->constrained('shops')->restrictOnDelete();
            $table->string('entity', 100);
            $table->unsignedBigInteger('local_id');
            $table->uuid('aggregate_id');
            $table->string('label')->nullable();
            $table->json('data');
            $table->dateTime('source_occurred_at', 3);
            $table->dateTime('removed_at', 3)->nullable();
            $table->timestamps();

            // One row per business record of a shop; local_id is the shop's own primary key.
            $table->unique(['shop_id', 'entity', 'local_id']);
            $table->index(['shop_id', 'entity', 'removed_at']);
            $table->index(['shop_id', 'entity', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_records');
    }
};
