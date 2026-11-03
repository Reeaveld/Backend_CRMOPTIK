<?php

namespace Tests\Unit;

use App\Services\BpjsPdfTextParser;
use Tests\TestCase;

/**
 * Test parser murni dengan fixture teks anonim. Test ini TIDAK boleh di-skip
 * dan tidak membutuhkan PDF asli (lihat BpjsImportTest untuk test integrasi).
 */
class BpjsPdfTextParserTest extends TestCase
{
    private function streamText(string $rows, int $count, int $total): string
    {
        return <<<TEXT
NO TRANSAKSI FORMULIR PENGAJUAN KLAIM (FPK)
NOMOR FPK : L2512000004029
OPTIK CENGKARENG INDAH
BULAN PELAYANAN : December, 2025
{$count}
{$total}
BPJS Kesehatan

NO TRANSAKSI
{$rows}
TEXT;
    }

    public function test_parses_stream_order_format_accurately(): void
    {
        $rows = "0115O006L2512000001 330,000 PASIEN SATU ANONIM 15/12/2025 Kacamata\n"
              . "01150006L2512000002 330,000 PASIEN DUA ANONIM 16/12/2025 Kacamata";

        $result = BpjsPdfTextParser::parse($this->streamText($rows, 2, 660000));

        $this->assertTrue($result['success']);
        $this->assertEquals('L2512000004029', $result['metadata']['nomor_fpk']);
        $this->assertEquals(2, $result['metadata']['total_data_header']);
        $this->assertEquals(660000, $result['metadata']['total_tagihan_header']);
        $this->assertEquals(2, $result['metadata']['total_parsed']);
        $this->assertEquals(660000, $result['metadata']['total_amount_parsed']);
        $this->assertTrue($result['metadata']['is_verified']);

        $this->assertCount(2, $result['rows']);
        $this->assertSame('0115O006L2512000001', $result['rows'][0]['invoice_number']);
        $this->assertEquals(330000, $result['rows'][0]['amount']);
        $this->assertSame('PASIEN SATU ANONIM', $result['rows'][0]['customer_name']);
        $this->assertSame('2025-12-15', $result['rows'][0]['transaction_date']);

        // Baris 2: karakter ke-5 angka nol (bukan huruf O) tetap dikenali
        $this->assertSame('01150006L2512000002', $result['rows'][1]['invoice_number']);
        $this->assertSame('PASIEN DUA ANONIM', $result['rows'][1]['customer_name']);
    }

    public function test_invoice_with_capital_letter_o_is_recognised(): void
    {
        $rows = '0115O006L2512000010 165,000 PASIEN HURUF O 02/12/2025 Kacamata';

        $result = BpjsPdfTextParser::parse($this->streamText($rows, 1, 165000));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('0115O006L2512000010', $result['rows'][0]['invoice_number']);
    }

    public function test_parses_visual_order_fallback_format(): void
    {
        $rawText = <<<TEXT
FORMULIR PENGAJUAN KLAIM (FPK) L2512000004029
OPTIK CENGKARENG
December, 2025
Total Data : 1 Total Tagihan : 330,000
NO TRANSAKSI
0115O006L2512000003 20/12/2025 PASIEN TIGA ANONIM Kacamata 330,000
TEXT;

        $result = BpjsPdfTextParser::parse($rawText);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('0115O006L2512000003', $result['rows'][0]['invoice_number']);
        $this->assertSame('PASIEN TIGA ANONIM', $result['rows'][0]['customer_name']);
        $this->assertSame('2025-12-20', $result['rows'][0]['transaction_date']);
        $this->assertEquals(330000, $result['rows'][0]['amount']);
        $this->assertTrue($result['metadata']['is_verified']);
    }

    public function test_invalid_text_returns_failure_result(): void
    {
        $result = BpjsPdfTextParser::parse('Dokumen acak tanpa struktur invoice BPJS apapun');

        $this->assertFalse($result['success']);
        $this->assertEmpty($result['rows']);
    }

    public function test_header_count_mismatch_marks_is_verified_false(): void
    {
        $rows = '0115O006L2512000001 330,000 PASIEN SATU ANONIM 15/12/2025 Kacamata';

        $result = BpjsPdfTextParser::parse($this->streamText($rows, 5, 1650000));

        $this->assertTrue($result['success']);
        $this->assertEquals(5, $result['metadata']['total_data_header']);
        $this->assertEquals(1, $result['metadata']['total_parsed']);
        $this->assertFalse($result['metadata']['is_verified']);
    }

    public function test_header_total_amount_mismatch_marks_is_verified_false(): void
    {
        $rows = '0115O006L2512000001 330,000 PASIEN SATU ANONIM 15/12/2025 Kacamata';

        // Jumlah baris cocok (1), total rupiah tidak cocok
        $result = BpjsPdfTextParser::parse($this->streamText($rows, 1, 999000));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['metadata']['is_verified']);
    }

    public function test_calendar_invalid_date_is_skipped_not_rewritten(): void
    {
        $rows = "0115O006L2512000001 330,000 PASIEN SATU ANONIM 31/02/2025 Kacamata\n"
              . "0115O006L2512000002 330,000 PASIEN DUA ANONIM 16/12/2025 Kacamata";

        $result = BpjsPdfTextParser::parse($this->streamText($rows, 2, 660000));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('0115O006L2512000002', $result['rows'][0]['invoice_number']);
        $this->assertSame(1, $result['metadata']['skipped_count']);
        $this->assertSame('0115O006L2512000001', $result['skipped_rows'][0]['invoice_number']);
        // Ada baris yang dilewati => tidak boleh dianggap terverifikasi
        $this->assertFalse($result['metadata']['is_verified']);
    }

    public function test_duplicate_invoice_in_same_file_is_flagged(): void
    {
        $rows = "0115O006L2512000001 330,000 PASIEN SATU ANONIM 15/12/2025 Kacamata\n"
              . "0115O006L2512000001 330,000 PASIEN SATU ANONIM 15/12/2025 Kacamata";

        $result = BpjsPdfTextParser::parse($this->streamText($rows, 2, 660000));

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['rows']);
        $this->assertFalse($result['rows'][0]['duplicate_in_file']);
        $this->assertTrue($result['rows'][1]['duplicate_in_file']);
        $this->assertSame(1, $result['metadata']['duplicate_in_file_count']);
    }

    public function test_missing_header_cannot_be_verified(): void
    {
        $rawText = "NO TRANSAKSI\n0115O006L2512000001 330,000 PASIEN SATU ANONIM 15/12/2025 Kacamata";

        $result = BpjsPdfTextParser::parse($rawText);

        $this->assertTrue($result['success']);
        $this->assertNull($result['metadata']['total_data_header']);
        $this->assertFalse($result['metadata']['is_verified']);
    }
}
