<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->boolean('notify_before')->default(false)->after('is_active');
            $table->boolean('notify_after')->default(false)->after('notify_before');
            $table->string('before_notification_time')->nullable()->after('notify_after');
            $table->string('after_notification_time')->nullable()->after('before_notification_time');
            $table->string('before_notification_class')->nullable()->after('after_notification_time');
            $table->string('after_notification_class')->nullable()->after('before_notification_class');
            $table->json('before_notification_data')->nullable()->after('after_notification_class');
            $table->json('after_notification_data')->nullable()->after('before_notification_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn([
                'notify_before',
                'notify_after',
                'before_notification_class',
                'after_notification_class',
                'before_notification_data',
                'after_notification_data',
            ]);
        });
    }
};
