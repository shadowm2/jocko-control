<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('shop_id')->constrained('shops')->restrictOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
