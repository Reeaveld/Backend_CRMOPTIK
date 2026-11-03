<?php

/*
|--------------------------------------------------------------------------
| Jenis dan interval follow-up
|--------------------------------------------------------------------------
|
| Hanya jenis di bawah ini yang dijadwalkan saat transaksi dibuat. Menambah
| jenis baru atau mengubah interval mengubah perilaku sistem bagi pelanggan,
| sehingga HARUS menunggu keputusan pemilik (K2). Pertahankan unique
| (transaction_id, type) pada follow_up_schedules.
|
| applies_to: 'all' | 'bpjs' | 'non_bpjs' (berdasarkan transactions.is_bpjs)
|
| Template pesan berada di App\Console\Commands\RunFollowUpBlast::buildMessage().
|
*/
return [
    'types' => [
        'h_plus_3'   => ['days' => 3,   'applies_to' => 'all'],
        'h_plus_330' => ['days' => 330, 'applies_to' => 'all'],
        // 'bpjs_claim_reminder' => ['days' => 700, 'applies_to' => 'bpjs'], // MENUNGGU K2
    ],
];
