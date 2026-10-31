<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexed copies of three facts the dashboard and order pages add up, so they
 * never have to dig into the JSON of every row: the record this one belongs
 * to (an order item's order), its amount, and the date it counts for.
 * Filled by the projector; `php artisan jocko:project-events` backfills rows
 * projected before this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_records', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('label');
            $table->decimal('amount', 18, 4)->nullable()->after('parent_id');
            $table->dateTime('dated_at', 3)->nullable()->after('amount');

            $table->index(['shop_id', 'entity', 'parent_id']);
            $table->index(['shop_id', 'entity', 'dated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shop_records', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'entity', 'parent_id']);
            $table->dropIndex(['shop_id', 'entity', 'dated_at']);
            $table->dropColumn(['parent_id', 'amount', 'dated_at']);
        });
    }
};
