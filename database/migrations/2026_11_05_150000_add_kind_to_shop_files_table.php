<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every file stored so far is a preview: originals could not be sent before.
        Schema::table('shop_files', function (Blueprint $table): void {
            $table->string('kind', 10)->default('preview')->after('media_id');
        });
    }

    public function down(): void
    {
        Schema::table('shop_files', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
