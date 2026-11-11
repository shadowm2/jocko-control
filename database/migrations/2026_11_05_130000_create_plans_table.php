<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('price')->default(0);
            $table->unsignedSmallInteger('billing_days')->default(30);
            $table->unsignedSmallInteger('max_devices')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('shops', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->after('status')->constrained('plans')->nullOnDelete();
            $table->date('paid_until')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn('paid_until');
        });

        Schema::dropIfExists('plans');
    }
};
