<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            // Which of mail, sms and internal the daily report goes through; null = no report.
            $table->json('digest_channels')->nullable()->after('keep_original_pictures');
            $table->string('digest_email')->nullable()->after('digest_channels');
            $table->string('digest_mobile', 30)->nullable()->after('digest_email');
            $table->string('digest_time', 5)->default('21:00')->after('digest_mobile');
            $table->date('digest_last_sent_on')->nullable()->after('digest_time');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['digest_channels', 'digest_email', 'digest_mobile', 'digest_time', 'digest_last_sent_on']);
        });
    }
};
