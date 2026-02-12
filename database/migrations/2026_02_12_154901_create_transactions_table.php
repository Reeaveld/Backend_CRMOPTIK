<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabel Header (ATM Frappe Sales Invoice + Krayin Quote)
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            
            // Frappe Style: Nomor Invoice Otomatis (INV-2024-0001)
            $table->string('invoice_number')->unique(); 
            
            $table->decimal('amount', 15, 2); // Total Harga
            $table->string('status')->default('process'); // process, done, cancelled
            $table->text('notes')->nullable(); // Catatan tambahan
            $table->date('transaction_date');
            
            $table->timestamps();
        });

        // 2. Tabel Child (ATM Krayin Quote Items)
        // Ini menyimpan detail resep spesifik untuk transaksi ini
        Schema::create('transaction_prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->onDelete('cascade');
            
            // Mata Kanan (OD - Oculus Dextra) atau Kiri (OS - Oculus Sinistra)
            $table->enum('eye_side', ['OD', 'OS']); 
            
            // Data Resep Mendalam (Scientific)
            $table->decimal('sphere', 5, 2)->default(0);    // Sph (Minus/Plus)
            $table->decimal('cylinder', 5, 2)->nullable();  // Cyl
            $table->integer('axis')->nullable();            // Axis (0-180 derajat)
            $table->decimal('addition', 5, 2)->nullable();  // Add (Baca baca)
            $table->decimal('pd', 5, 2)->nullable();        // Pupil Distance
            
            $table->string('lens_type')->nullable(); // Progressive, Bluechromic, dll
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_prescriptions');
        Schema::dropIfExists('transactions');
    }
};