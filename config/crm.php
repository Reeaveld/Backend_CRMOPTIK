<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evaluasi efektivitas (Rumusan Masalah 3)
    |--------------------------------------------------------------------------
    |
    | go_live_date          : tanggal sistem follow-up mulai dipakai (YYYY-MM-DD).
    |                         Kosong = perbandingan sebelum-sesudah tidak dihitung.
    |                         Nilai resmi menunggu keputusan pemilik (K5).
    | comparison_window_days: panjang tiap jendela sebelum/sesudah go-live.
    | followup_return_days  : N hari setelah pesan 'sent' untuk menghitung pembelian
    |                         ulang (hanya 30/60/90 yang diterima endpoint).
    |
    */
    'go_live_date'           => env('CRM_GO_LIVE_DATE') ?: null,
    'comparison_window_days' => (int) env('CRM_COMPARISON_WINDOW_DAYS', 90),
    'followup_return_days'   => (int) env('CRM_FOLLOWUP_RETURN_DAYS', 30),

];
