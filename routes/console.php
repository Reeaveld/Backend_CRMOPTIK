<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// =====================================================================
// SCHEDULED TASKS — CRM Automated Follow-Up
// =====================================================================
// Dijalankan setiap hari oleh `php artisan schedule:run` (via cron).
// Memproses follow_up_schedules yang jatuh tempo dan mengirim pesan
// WhatsApp kepada pelanggan yang profilnya sudah lengkap.
Schedule::command('crm:send-followups')->daily();
