<?php

namespace App\Console\Commands;

use App\Models\FollowUpSchedule;
use App\Services\Contracts\WhatsAppServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command yang memproses jadwal follow-up jatuh tempo.
 *
 * Alur kerja:
 *   1. Query semua follow_up_schedules dengan status 'pending' dan scheduled_date <= hari ini.
 *   2. Untuk setiap jadwal, cek ulang isProfileComplete() pada customer terkait.
 *      - Jika false: ubah status ke 'blocked_incomplete_profile', log warning.
 *      - Jika true: kirim pesan via WhatsAppService, update status ke 'sent' atau 'failed'.
 *   3. Catat ringkasan eksekusi di output console dan log file.
 *
 * Penggunaan:
 *   php artisan crm:send-followups          (eksekusi manual)
 *   Schedule::command('crm:send-followups') (otomatis via scheduler)
 */
class RunFollowUpBlast extends Command
{
    protected $signature = 'crm:send-followups';
    protected $description = 'Proses dan kirim pesan follow-up yang sudah jatuh tempo.';

    public function handle(WhatsAppServiceInterface $whatsApp): int
    {
        $this->info('=== CRM Follow-Up Blast ===');
        $this->info('Waktu eksekusi: ' . now()->toDateTimeString());

        // Query jadwal jatuh tempo hari ini (atau yang sudah lewat tapi belum terkirim)
        $dueSchedules = FollowUpSchedule::dueToday()
            ->with(['customer', 'transaction'])
            ->get();

        if ($dueSchedules->isEmpty()) {
            $this->info('Tidak ada jadwal follow-up jatuh tempo hari ini.');
            Log::info('[FollowUpBlast] Tidak ada jadwal jatuh tempo.');
            return self::SUCCESS;
        }

        $this->info("Ditemukan {$dueSchedules->count()} jadwal jatuh tempo.");

        $sent = 0;
        $blocked = 0;
        $failed = 0;

        foreach ($dueSchedules as $schedule) {
            $customer = $schedule->customer;

            // Safety check: pastikan customer dan transaksi masih ada
            if (!$customer || !$schedule->transaction) {
                $schedule->update([
                    'status' => FollowUpSchedule::STATUS_FAILED,
                    'notes'  => 'Customer atau transaksi tidak ditemukan (kemungkinan sudah dihapus).',
                ]);
                $failed++;
                continue;
            }

            // Cek ulang kelengkapan profil — bisa saja profil berubah sejak jadwal dibuat
            if (!$customer->isProfileComplete()) {
                $schedule->update([
                    'status' => FollowUpSchedule::STATUS_BLOCKED,
                    'notes'  => 'Profil customer belum lengkap (no_hp kosong/invalid). '
                              . 'Jadwal akan otomatis aktif setelah admin melengkapi profil.',
                ]);
                $blocked++;

                Log::warning('[FollowUpBlast] Jadwal diblokir — profil belum lengkap.', [
                    'schedule_id' => $schedule->id,
                    'customer'    => $customer->nama,
                    'type'        => $schedule->type,
                ]);
                continue;
            }

            // Bangun isi pesan berdasarkan tipe follow-up
            $message = $this->buildMessage($schedule);

            // Kirim via WhatsApp Gateway
            $success = $whatsApp->sendMessage($customer->no_hp, $message);

            if ($success) {
                $schedule->update([
                    'status'  => FollowUpSchedule::STATUS_SENT,
                    'sent_at' => now(),
                    'notes'   => null,
                ]);
                $sent++;

                Log::info('[FollowUpBlast] Pesan terkirim.', [
                    'schedule_id' => $schedule->id,
                    'customer'    => $customer->nama,
                    'phone'       => $customer->no_hp,
                    'type'        => $schedule->type,
                ]);
            } else {
                $schedule->update([
                    'status' => FollowUpSchedule::STATUS_FAILED,
                    'notes'  => 'Gagal mengirim pesan via WhatsApp Gateway.',
                ]);
                $failed++;

                Log::error('[FollowUpBlast] Gagal kirim pesan.', [
                    'schedule_id' => $schedule->id,
                    'customer'    => $customer->nama,
                    'phone'       => $customer->no_hp,
                    'type'        => $schedule->type,
                ]);
            }
        }

        // Ringkasan
        $this->newLine();
        $this->info("=== Ringkasan ===");
        $this->info("Terkirim : {$sent}");
        $this->info("Terblokir: {$blocked} (profil belum lengkap)");
        $this->info("Gagal    : {$failed}");

        Log::info('[FollowUpBlast] Selesai.', compact('sent', 'blocked', 'failed'));

        return self::SUCCESS;
    }

    /**
     * Membangun isi pesan WhatsApp berdasarkan tipe follow-up.
     */
    private function buildMessage(FollowUpSchedule $schedule): string
    {
        $customerName = $schedule->customer->nama ?? 'Pelanggan';
        $invoice = $schedule->transaction->invoice_number ?? '-';

        return match ($schedule->type) {
            FollowUpSchedule::TYPE_H_PLUS_3 => "Halo {$customerName}, terima kasih telah bertransaksi di Optik RS Cengkareng (Invoice: {$invoice}). "
                . "Bagaimana kondisi kacamata/lensa Anda? Apakah sudah nyaman digunakan? "
                . "Jika ada keluhan, silakan hubungi kami. Terima kasih! 🙏",

            FollowUpSchedule::TYPE_H_PLUS_330 => "Halo {$customerName}, sudah hampir setahun sejak transaksi terakhir Anda di Optik RS Cengkareng (Invoice: {$invoice}). "
                . "Kami menyarankan pemeriksaan mata rutin setiap 12 bulan untuk menjaga kesehatan penglihatan Anda. "
                . "Silakan kunjungi kami untuk pemeriksaan gratis. Terima kasih! 👁️",

            default => "Halo {$customerName}, ini adalah pesan pengingat dari Optik RS Cengkareng. Terima kasih atas kunjungan Anda!",
        };
    }
}
