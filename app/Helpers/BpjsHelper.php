<?php

namespace App\Helpers;

class BpjsHelper
{
    /**
     * Pola regex dasar nomor invoice klaim BPJS Optik Cengkareng.
     * Karakter ke-5 dapat berupa huruf 'O' kapital atau angka '0'.
     */
    public static function pattern(): string
    {
        return config('services.bpjs.invoice_pattern', '0115[O00-9]006L');
    }

    /**
     * Memeriksa apakah suatu nomor invoice merupakan klaim BPJS.
     */
    public static function isBpjsInvoice(?string $invoiceNumber): bool
    {
        if (empty($invoiceNumber)) {
            return false;
        }

        $pattern = self::pattern();
        return (bool) preg_match('/' . $pattern . '/i', $invoiceNumber);
    }
}
