<?php

namespace Tests\Unit;

use App\Helpers\BpjsHelper;
use Tests\TestCase;

class BpjsHelperTest extends TestCase
{
    public function test_invoice_with_capital_letter_o_is_bpjs(): void
    {
        $this->assertTrue(BpjsHelper::isBpjsInvoice('0115O006L2512000001'));
    }

    public function test_invoice_with_digit_zero_variant_is_bpjs(): void
    {
        $this->assertTrue(BpjsHelper::isBpjsInvoice('01150006L2512000001'));
    }

    public function test_non_bpjs_and_empty_invoices_are_rejected(): void
    {
        $this->assertFalse(BpjsHelper::isBpjsInvoice('INV/2026/ABCDE'));
        $this->assertFalse(BpjsHelper::isBpjsInvoice(''));
        $this->assertFalse(BpjsHelper::isBpjsInvoice(null));
    }

    public function test_pattern_must_match_at_start_and_end_with_digits(): void
    {
        $this->assertFalse(BpjsHelper::isBpjsInvoice('XX0115O006L2512000001'));
        $this->assertFalse(BpjsHelper::isBpjsInvoice('0115O006L2512000001-X'));
        $this->assertFalse(BpjsHelper::isBpjsInvoice('0115O006L'));
    }

    public function test_pattern_is_read_from_single_config_source(): void
    {
        config(['services.bpjs.invoice_pattern' => '9999X006L']);

        $this->assertSame('9999X006L', BpjsHelper::pattern());
        $this->assertTrue(BpjsHelper::isBpjsInvoice('9999X006L12345'));
        $this->assertFalse(BpjsHelper::isBpjsInvoice('0115O006L2512000001'));
    }

    public function test_legacy_prefix_config_no_longer_exists(): void
    {
        $this->assertNull(config('services.bpjs.invoice_prefix'));
    }
}
