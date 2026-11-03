<?php

namespace App\Helpers;

class BpjsHelper
{
    /**
     * Pola regex dasar nomor invoice klaim BPJS Optik Cengkareng (prefix saja).
     * Karakter ke-5 dapat berupa huruf 'O' kapital atau angka (format asli: 0115O006L + digit).
     * Satu-satunya sumber konfigurasi: services.bpjs.invoice_pattern (env BPJS_INVOICE_PATTERN).
     */
    public static function pattern(): string
    {
        return config('services.bpjs.invoice_pattern', '0115[O0-9]006L');
    }

    /**
     * Memeriksa apakah suatu nomor invoice merupakan klaim BPJS.
     */
    public static function isBpjsInvoice(?string $invoiceNumber): bool
    {
        if (empty($invoiceNumber)) {
            return false;
        }

        // Diawali pola provider, diikuti hanya digit (nomor urut)
        return (bool) preg_match('/^' . self::pattern() . '\d+$/i', trim($invoiceNumber));
    }
}
