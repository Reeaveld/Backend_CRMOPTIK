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

/**
 * Test integrasi import BPJS.
 *
 * Test yang memakai PDF asli membaca path dari env BPJS_SAMPLE_PDF dan akan
 * di-skip dengan pesan jelas bila tidak diset. PDF asli (berisi data pasien)
 * TIDAK boleh masuk repositori. Logika parsing diuji tanpa PDF di
 * tests/Unit/BpjsPdfTextParserTest.php.
 */
class BpjsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Sanctum::actingAs($user);
    }

    private function samplePdfOrSkip(): string
    {
        $path = getenv('BPJS_SAMPLE_PDF') ?: ($_ENV['BPJS_SAMPLE_PDF'] ?? '');

        if ($path === '' || !is_file($path)) {
            $this->markTestSkipped(
                'SKIP: set env BPJS_SAMPLE_PDF ke path PDF tagihan BPJS asli untuk menjalankan test integrasi ini '
                . '(PDF asli tidak disimpan di repo).'
            );
        }

        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, 'klaim_bpjs.pdf', 'application/pdf', null, true);
    }

    private function validItem(array $overrides = []): array
    {
        return array_merge([
            'invoice_number'   => '0115O006L2512000001',
            'transaction_date' => '2025-12-01',
            'customer_name'    => 'PASIEN SATU ANONIM',
            'amount'           => 165000,
            'phone'            => null,
        ], $overrides);
    }

    // ─── Integrasi dengan PDF asli (opsional, via env) ──────────────

    public function test_parse_bpjs_real_pdf_is_verified_against_its_own_header(): void
    {
        $path = $this->samplePdfOrSkip();

        $response = $this->postJson('/api/import/bpjs/parse', ['file' => $this->upload($path)]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $meta = $response->json('metadata');
        $items = $response->json('items');

        $this->assertNotEmpty($items);
        $this->assertSame($meta['total_data_header'], $meta['total_parsed']);
        $this->assertSame($meta['total_data_header'], count($items));
        $this->assertEqualsWithDelta($meta['total_tagihan_header'], $meta['total_amount_parsed'], 1);
        $this->assertTrue($meta['is_verified']);

        foreach ($items as $item) {
            $this->assertMatchesRegularExpression('/^0115[O0]006L\d{10,12}$/', $item['invoice_number']);
            $this->assertNull($item['phone'] ?? null, 'PDF tidak memuat nomor HP.');
        }
    }

    public function test_parse_bpjs_real_pdf_marks_existing_invoice_as_duplicate(): void
    {
        $path = $this->samplePdfOrSkip();

        $first = $this->postJson('/api/import/bpjs/parse', ['file' => $this->upload($path)]);
        $first->assertStatus(200);
        $row = $first->json('items.0');

        $customer = Customer::create(['nama' => $row['customer_name'], 'no_hp' => '08123456789']);
        Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => $row['invoice_number'],
            'amount'           => $row['amount'],
            'status'           => 'done',
            'notes'            => 'Transaksi lama',
            'transaction_date' => $row['transaction_date'],
        ]);

        $second = $this->postJson('/api/import/bpjs/parse', ['file' => $this->upload($path)]);
        $second->assertStatus(200);
        $item = $second->json('items.0');

        $this->assertTrue($item['is_duplicate']);
        $this->assertFalse($item['selected']);
        $this->assertTrue($item['is_existing_customer']);
        $this->assertSame('08123456789', $item['suggested_phone']);
    }

    public function test_parse_bpjs_rejects_non_pdf_upload(): void
    {
        $file = UploadedFile::fake()->create('bukan.txt', 5, 'text/plain');

        $this->postJson('/api/import/bpjs/parse', ['file' => $file])
            ->assertStatus(422);
    }

    // ─── Commit ─────────────────────────────────────────────────────

    public function test_commit_bpjs_saves_records_and_schedules_followups(): void
    {
        $payload = [
            'nomor_fpk' => 'L2512000004029',
            'items'     => [
                $this->validItem([
                    'customer_name' => 'PASIEN SATU ANONIM',
                    'phone'         => '081298765432',
                ]),
                $this->validItem([
                    'invoice_number' => '0115O006L2512000002',
                    'customer_name'  => 'PASIEN DUA ANONIM',
                    'amount'         => 330000,
                    'phone'          => null,
                ]),
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

        $satu = Customer::where('nama', 'PASIEN SATU ANONIM')->first();
        $this->assertNotNull($satu);
        $this->assertEquals('081298765432', $satu->no_hp);
        $this->assertTrue($satu->isProfileComplete());

        $dua = Customer::where('nama', 'PASIEN DUA ANONIM')->first();
        $this->assertNotNull($dua);
        $this->assertNull($dua->no_hp);
        $this->assertFalse($dua->isProfileComplete());

        $this->assertDatabaseHas('transactions', [
            'invoice_number' => '0115O006L2512000001',
            'customer_id'    => $satu->id,
            'amount'         => 165000,
            'status'         => 'done',
            'is_bpjs'        => 1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'invoice_number' => '0115O006L2512000002',
            'customer_id'    => $dua->id,
            'amount'         => 330000,
            'status'         => 'done',
        ]);

        $satuSchedules = FollowUpSchedule::where('customer_id', $satu->id)->get();
        $this->assertCount(2, $satuSchedules);
        $this->assertEquals(
            [FollowUpSchedule::STATUS_PENDING, FollowUpSchedule::STATUS_PENDING],
            $satuSchedules->pluck('status')->all()
        );

        $duaSchedules = FollowUpSchedule::where('customer_id', $dua->id)->get();
        $this->assertCount(2, $duaSchedules);
        $this->assertEquals(
            [FollowUpSchedule::STATUS_BLOCKED, FollowUpSchedule::STATUS_BLOCKED],
            $duaSchedules->pluck('status')->all()
        );
    }

    public function test_commit_bpjs_unblocks_old_schedules_when_phone_is_updated(): void
    {
        $customer = Customer::create(['nama' => 'PASIEN LAMA ANONIM', 'no_hp' => null]);

        $oldTx = Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => 'INV-OLD-999',
            'amount'           => 100000,
            'status'           => 'done',
            'transaction_date' => Carbon::today()->toDateString(),
        ]);

        FollowUpSchedule::create([
            'customer_id'    => $customer->id,
            'transaction_id' => $oldTx->id,
            'type'           => FollowUpSchedule::TYPE_H_PLUS_3,
            'scheduled_date' => Carbon::today(),
            'status'         => FollowUpSchedule::STATUS_BLOCKED,
        ]);

        $response = $this->postJson('/api/import/bpjs/commit', [
            'nomor_fpk' => 'L2512000004029',
            'items'     => [
                $this->validItem([
                    'invoice_number'   => '0115O006L2512099999',
                    'transaction_date' => '2025-12-15',
                    'customer_name'    => 'PASIEN LAMA ANONIM',
                    'amount'           => 220000,
                    'phone'            => '085712345678',
                ]),
            ],
        ]);
        $response->assertStatus(200);

        $customer->refresh();
        $this->assertEquals('085712345678', $customer->no_hp);

        $oldSchedule = FollowUpSchedule::where('transaction_id', $oldTx->id)->first();
        $this->assertEquals(FollowUpSchedule::STATUS_PENDING, $oldSchedule->status);
    }

    public function test_commit_skips_invoice_that_already_exists(): void
    {
        $this->postJson('/api/import/bpjs/commit', ['items' => [$this->validItem()]])
            ->assertStatus(200)
            ->assertJson(['imported_count' => 1]);

        $this->postJson('/api/import/bpjs/commit', ['items' => [$this->validItem()]])
            ->assertStatus(200)
            ->assertJson(['imported_count' => 0]);

        $this->assertSame(1, Transaction::where('invoice_number', '0115O006L2512000001')->count());
    }

    public function test_commit_rejects_non_bpjs_invoice_format(): void
    {
        $this->postJson('/api/import/bpjs/commit', [
            'items' => [$this->validItem(['invoice_number' => 'INV/2026/ABCDE'])],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.invoice_number']);
    }

    public function test_commit_rejects_negative_amount(): void
    {
        $this->postJson('/api/import/bpjs/commit', [
            'items' => [$this->validItem(['amount' => -1])],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.amount']);
    }

    public function test_commit_rejects_future_and_ancient_dates(): void
    {
        $this->postJson('/api/import/bpjs/commit', [
            'items' => [$this->validItem(['transaction_date' => Carbon::tomorrow()->toDateString()])],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.transaction_date']);

        $this->postJson('/api/import/bpjs/commit', [
            'items' => [$this->validItem(['transaction_date' => '1999-01-01'])],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.transaction_date']);
    }

    public function test_commit_rejects_more_than_500_items(): void
    {
        $items = [];
        for ($i = 0; $i < 501; $i++) {
            $items[] = $this->validItem(['invoice_number' => '0115O006L25120' . str_pad((string) $i, 5, '0', STR_PAD_LEFT)]);
        }

        $this->postJson('/api/import/bpjs/commit', ['items' => $items])
            ->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->assertSame(0, Transaction::count());
    }
}
