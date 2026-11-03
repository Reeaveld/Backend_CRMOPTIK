<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webhook "Update Message Status" Fonnte mengirim: device, id, stateid, status, state.
     * Kolom provider_status menyimpan `status` (sent/pending/invalid/...), sedangkan
     * `state` (mis. delivered/read) dan `stateid` disimpan terpisah.
     */
    public function up(): void
    {
        Schema::table('follow_up_schedules', function (Blueprint $table) {
            $table->string('provider_state')->nullable()->after('provider_status');
            $table->string('provider_stateid')->nullable()->after('provider_state');

            $table->index('provider_stateid');
        });
    }

    public function down(): void
    {
        Schema::table('follow_up_schedules', function (Blueprint $table) {
            $table->dropIndex(['provider_stateid']);
            $table->dropColumn(['provider_state', 'provider_stateid']);
        });
    }
};
