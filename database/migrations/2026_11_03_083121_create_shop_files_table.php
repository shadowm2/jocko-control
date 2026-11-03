<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One preview image per `media` record of a shop (see ShopFileStore).
        Schema::create('shop_files', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('shop_id')->constrained('shops')->restrictOnDelete();
            $table->unsignedBigInteger('media_id');
            $table->char('sha256', 64);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size');
            $table->string('path');
            $table->timestamps();

            $table->unique(['shop_id', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_files');
    }
};
