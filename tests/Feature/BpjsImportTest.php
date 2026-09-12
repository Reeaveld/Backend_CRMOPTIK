<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BpjsImportTest extends TestCase
{
    use RefreshDatabase;

    private string $samplePdfPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->samplePdfPath = 'C:/Users/user/.gemini/antigravity-ide/brain/e1e0aae1-f45d-491b-824a-55c719845b39/.user_uploaded/media_1789188300266.pdf';

        // Autentikasi default admin untuk Sanctum
        $user = User::factory()->create();
        Sanctum::actingAs($user);
    }

    public function test_parse_bpjs_extracts_all_141_rows_and_metadata_accurately(): void
    {
        if (!file_exists($this->samplePdfPath)) {
            $this->markTestSkipped('Sample PDF file not found at expected path.');
        }

        $uploadedFile = new UploadedFile(
            $this->samplePdfPath,
            'klaim_bpjs_desember_2025.pdf',
            'application/pdf',
            null,
            true
        );

        $response = $this->postJson('/api/import/bpjs/parse', [
            'file' => $uploadedFile,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'metadata' => [
                    'nomor_fpk'            => 'L2512000004029',
                    'total_data_header'    => 141,
                    'total_tagihan_header' => 32340000,
                    'total_parsed'         => 141,
                    'total_amount_parsed'  => 32340000,
                    'is_verified'          => true,
                ],
            ]);

        $items = $response->json('items');
        $this->assertCount(141, $items);

        // Baris pertama: SAMSIBAR
        $this->assertEquals('0115O006L2512000464', $items[0]['invoice_number']);
        $this->assertEquals('SAMSIBAR', $items[0]['customer_name']);
        $this->assertEquals(165000, $items[0]['amount']);
        $this->assertEquals('2025-12-01', $items[0]['transaction_date']);
        $this->assertFalse($items[0]['is_duplicate']);
        $this->assertTrue($items[0]['selected']);

        // Baris terakhir: LILIS
        $last = end($items);
        $this->assertEquals('0115O006L2512177778', $last['invoice_number']);
        $this->assertEquals('LILIS', $last['customer_name']);
        $this->assertEquals(165000, $last['amount']);
        $this->assertEquals('2025-12-31', $last['transaction_date']);
    }

    public function test_parse_bpjs_auto_unchecks_existing_duplicate_invoices(): void
    {
        if (!file_exists($this->samplePdfPath)) {
            $this->markTestSkipped('Sample PDF file not found.');
        }

        $customer = Customer::create([
            'nama'  => 'SAMSIBAR',
            'no_hp' => '08123456789',
        ]);

        Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => '0115O006L2512000464',
            'amount'           => 165000,
            'status'           => 'done',
            'notes'            => 'Transaksi lama',
            'transaction_date' => '2025-12-01',
        ]);

        $uploadedFile = new UploadedFile(
            $this->samplePdfPath,
            'klaim.pdf',
            'application/pdf',
            null,
            true
        );

        $response = $this->postJson('/api/import/bpjs/parse', [
            'file' => $uploadedFile,
        ]);

        $response->assertStatus(200);
        $items = $response->json('items');

        // SAMSIBAR harus ditandai duplicate dan otomatis uncheck
        $this->assertTrue($items[0]['is_duplicate']);
        $this->assertFalse($items[0]['selected']);
        $this->assertTrue($items[0]['is_existing_customer']);
        $this->assertEquals('08123456789', $items[0]['suggested_phone']);
    }

    public function test_commit_bpjs_saves_records_and_schedules_followups(): void
    {
        $payload = [
            'nomor_fpk' => 'L2512000004029',
            'items'     => [
                [
                    'invoice_number'   => '0115O006L2512000464',
                    'transaction_date' => '2025-12-01',
                    'customer_name'    => 'SAMSIBAR',
                    'amount'           => 165000,
                    'phone'            => '081298765432', // Nomor valid
                ],
                [
                    'invoice_number'   => '0115O006L2512000467',
                    'transaction_date' => '2025-12-01',
                    'customer_name'    => 'SUMIYATI',
                    'amount'           => 330000,
                    'phone'            => null, // Tanpa nomor HP
                ],
            ],
        ];

        $response = $this->postJson('/api/import/bpjs/commit', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success'           => true,
                'imported_count'    => 2,
                'customers_created' => 2,
                'schedules_pending' => 2,
                'schedules_blocked' => 2,
            ]);

        // Verifikasi Customer
        $samsibar = Customer::where('nama', 'SAMSIBAR')->first();
        $this->assertNotNull($samsibar);
        $this->assertEquals('081298765432', $samsibar->no_hp);
        $this->assertTrue($samsibar->isProfileComplete());

        $sumiyati = Customer::where('nama', 'SUMIYATI')->first();
        $this->assertNotNull($sumiyati);
        $this->assertNull($sumiyati->no_hp);
        $this->assertFalse($sumiyati->isProfileComplete());

        // Verifikasi Transactions
        $this->assertDatabaseHas('transactions', [
            'invoice_number' => '0115O006L2512000464',
            'customer_id'    => $samsibar->id,
            'amount'         => 165000,
            'status'         => 'done',
        ]);

        $this->assertDatabaseHas('transactions', [
            'invoice_number' => '0115O006L2512000467',
            'customer_id'    => $sumiyati->id,
            'amount'         => 330000,
            'status'         => 'done',
        ]);

        // Verifikasi Jadwal Follow-Up (Samsibar -> Pending)
        $samsibarSchedules = FollowUpSchedule::where('customer_id', $samsibar->id)->get();
        $this->assertCount(2, $samsibarSchedules);
        $this->assertEquals(FollowUpSchedule::STATUS_PENDING, $samsibarSchedules[0]->status);
        $this->assertEquals(FollowUpSchedule::STATUS_PENDING, $samsibarSchedules[1]->status);

        // Verifikasi Jadwal Follow-Up (Sumiyati -> Blocked)
        $sumiyatiSchedules = FollowUpSchedule::where('customer_id', $sumiyati->id)->get();
        $this->assertCount(2, $sumiyatiSchedules);
        $this->assertEquals(FollowUpSchedule::STATUS_BLOCKED, $sumiyatiSchedules[0]->status);
        $this->assertEquals(FollowUpSchedule::STATUS_BLOCKED, $sumiyatiSchedules[1]->status);
    }

    public function test_commit_bpjs_unblocks_old_schedules_when_phone_is_updated(): void
    {
        // Pasien lama dengan jadwal terblokir
        $customer = Customer::create([
            'nama'  => 'RETNO WULANDARI',
            'no_hp' => null,
        ]);

        $oldTx = Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => 'INV-OLD-999',
            'amount'           => 100000,
            'status'           => 'done',
            'transaction_date' => Carbon::yesterday()->toDateString(),
        ]);

        FollowUpSchedule::create([
            'customer_id'    => $customer->id,
            'transaction_id' => $oldTx->id,
            'type'           => FollowUpSchedule::TYPE_H_PLUS_3,
            'scheduled_date' => Carbon::today(),
            'status'         => FollowUpSchedule::STATUS_BLOCKED,
        ]);

        $payload = [
            'nomor_fpk' => 'L2512000004029',
            'items'     => [
                [
                    'invoice_number'   => '0115O006L2512099999',
                    'transaction_date' => '2025-12-15',
                    'customer_name'    => 'RETNO WULANDARI',
                    'amount'           => 220000,
                    'phone'            => '085712345678', // Dilengkapi sekarang
                ],
            ],
        ];

        $response = $this->postJson('/api/import/bpjs/commit', $payload);
        $response->assertStatus(200);

        // Nomor HP harus terupdate
        $customer->refresh();
        $this->assertEquals('085712345678', $customer->no_hp);

        // Jadwal lama yang tadinya blocked harus berubah jadi pending
        $oldSchedule = FollowUpSchedule::where('transaction_id', $oldTx->id)->first();
        $this->assertEquals(FollowUpSchedule::STATUS_PENDING, $oldSchedule->status);
    }
}
