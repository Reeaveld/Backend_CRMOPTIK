<?php

namespace App\Services;

use App\Helpers\BpjsHelper;
use Carbon\Carbon;

/**
 * Parser murni (tanpa I/O) untuk teks hasil ekstraksi PDF tagihan klaim BPJS.
 *
 * PDF hanya memuat 5 kolom: NO TRANSAKSI, Biaya, NAMA PESERTA, TANGGAL, JENIS KLAIM.
 * Tidak ada nomor HP dan tidak ada resep.
 *
 * Pustaka PDF dapat menghasilkan dua urutan keluaran, keduanya didukung:
 *   1. "stream order": invoice, biaya, nama, tanggal, jenis klaim
 *   2. "visual order": invoice, tanggal, nama, jenis klaim, biaya
 */
class BpjsPdfTextParser
{
    /**
     * @return array{
     *   success: bool,
     *   message?: string,
     *   metadata: ?array,
     *   rows: array<int, array>,
     *   skipped_rows?: array<int, array>
     * }
     */
    public static function parse(string $text): array
    {
        // 1. Ekstraksi Header Metadata
        $headerSection = strstr($text, 'NO TRANSAKSI', true) ?: substr($text, 0, 1500);

        preg_match('/(L\d{13})/i', $headerSection, $fpkMatch);
        preg_match('/OPTIK\s+[^\r\n]+/i', $headerSection, $providerMatch);
        preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December),\s*\d{4}/i', $headerSection, $bulanMatch);

        $fpkNumber = $fpkMatch[1] ?? null;
        $provider = trim($providerMatch[0] ?? 'Optik CRM');
        $bulanPelayanan = trim($bulanMatch[0] ?? '');

        $headerTotalData = null;
        $headerTotalTagihan = null;

        // Format stream (kolom angka terpisah baris baru sebelum "BPJS Kesehatan")
        if (preg_match('/(\d+)\s*\n([\d,]+)\s*\nBPJS/i', $headerSection, $streamTotals)) {
            $headerTotalData = (int) $streamTotals[1];
            $headerTotalTagihan = (float) str_replace(',', '', $streamTotals[2]);
        } elseif (preg_match('/Total\s+Data\s*[:\s]+(\d+)/i', $headerSection, $tdMatch) &&
                  preg_match('/Total\s+Tagihan\s*[:\s]+([\d,.]+)/i', $headerSection, $ttMatch)) {
            $headerTotalData = (int) $tdMatch[1];
            $headerTotalTagihan = (float) str_replace(',', '', $ttMatch[1]);
        }

        // 2. Ekstraksi Baris Data Transaksi
        $bpjsPrefixPattern = BpjsHelper::pattern();
        $pattern1 = '/(' . $bpjsPrefixPattern . '\d{10,12})\s+([\d,.]+)\s*(.+?)\s*(\d{2}\/\d{2}\/\d{4})\s+Kacamata/i';
        preg_match_all($pattern1, $text, $rawMatches, PREG_SET_ORDER);

        $isOrderInverted = true;
        if (empty($rawMatches)) {
            $pattern2 = '/(' . $bpjsPrefixPattern . '\d{10,12})\s+(\d{2}\/\d{2}\/\d{4})\s+(.+?)\s+Kacamata\s+([\d,.]+)/i';
            preg_match_all($pattern2, $text, $rawMatches, PREG_SET_ORDER);
            $isOrderInverted = false;
        }

        if (empty($rawMatches)) {
            return [
                'success'  => false,
                'message'  => 'Format tabel klaim BPJS tidak dikenali atau tidak ada data yang cocok dengan pola invoice.',
                'metadata' => null,
                'rows'     => [],
            ];
        }

        $parsedRows = [];
        $skippedRows = [];
        $seenInvoices = [];
        $duplicateInFile = 0;
        $totalAmountParsed = 0.0;

        foreach ($rawMatches as $index => $match) {
            $invoice = trim($match[1]);
            if ($isOrderInverted) {
                $amount = (float) str_replace(',', '', $match[2]);
                $customerName = strtoupper(trim($match[3]));
                $dateRaw = trim($match[4]);
            } else {
                $dateRaw = trim($match[2]);
                $customerName = strtoupper(trim($match[3]));
                $amount = (float) str_replace(',', '', $match[4]);
            }

            $formattedDate = self::parseStrictDate($dateRaw);
            if ($formattedDate === null) {
                // Jangan mengarang tanggal: baris dilewati dan dilaporkan.
                $skippedRows[] = [
                    'invoice_number' => $invoice,
                    'reason'         => 'Tanggal tidak valid: ' . $dateRaw,
                ];
                continue;
            }

            $isDuplicateInFile = isset($seenInvoices[$invoice]);
            $seenInvoices[$invoice] = true;
            if ($isDuplicateInFile) {
                $duplicateInFile++;
            }

            $totalAmountParsed += $amount;

            $parsedRows[] = [
                'temp_id'            => $index + 1,
                'invoice_number'     => $invoice,
                'transaction_date'   => $formattedDate,
                'customer_name'      => $customerName,
                'claim_type'         => 'Kacamata',
                'amount'             => $amount,
                'duplicate_in_file'  => $isDuplicateInFile,
            ];
        }

        $totalParsed = count($parsedRows);

        // Verifikasi terhadap header. Tanpa header, dokumen tidak dapat diverifikasi.
        $countVerified = $headerTotalData !== null && $headerTotalData === $totalParsed;
        $amountVerified = $headerTotalTagihan !== null && abs($totalAmountParsed - $headerTotalTagihan) < 1;
        $isVerified = $countVerified && $amountVerified && empty($skippedRows);

        return [
            'success'      => true,
            'metadata'     => [
                'nomor_fpk'               => $fpkNumber,
                'provider'                => $provider,
                'bulan_pelayanan'         => $bulanPelayanan,
                'total_data_header'       => $headerTotalData,
                'total_tagihan_header'    => $headerTotalTagihan,
                'total_parsed'            => $totalParsed,
                'total_amount_parsed'     => $totalAmountParsed,
                'skipped_count'           => count($skippedRows),
                'duplicate_in_file_count' => $duplicateInFile,
                'is_verified'             => $isVerified,
            ],
            'rows'         => $parsedRows,
            'skipped_rows' => $skippedRows,
        ];
    }

    /**
     * Parse dd/mm/YYYY secara ketat. Tanggal yang tidak ada di kalender
     * (mis. 31/02/2025) ditolak, bukan digeser ke bulan berikutnya.
     */
    private static function parseStrictDate(string $raw): ?string
    {
        $date = \DateTime::createFromFormat('!d/m/Y', $raw);
        $errors = \DateTime::getLastErrors();

        if ($date === false) {
            return null;
        }
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return Carbon::instance($date)->toDateString();
    }
}
