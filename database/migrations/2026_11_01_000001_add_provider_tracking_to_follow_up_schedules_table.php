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
        Schema::table('follow_up_schedules', function (Blueprint $table) {
            $table->string('provider_message_id')->nullable()->after('sent_at');
            $table->string('provider_status')->nullable()->after('provider_message_id'); // 'pending', 'sent', 'delivered', 'read', 'failed'
            $table->timestamp('delivered_at')->nullable()->after('provider_status');

            $table->index('provider_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('follow_up_schedules', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn(['provider_message_id', 'provider_status', 'delivered_at']);
        });
    }
};

