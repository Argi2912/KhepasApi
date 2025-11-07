<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('description');                                   // Ej: "Comisión Corredor (Simon Eloy) - Sol. #1234"
            $table->decimal('amount', 14, 2);                                // Monto
            $table->enum('type', ['payable', 'receivable']);                 // Por Pagar / Por Cobrar
            $table->enum('status', ['pending', 'paid'])->default('pending'); // Pendiente / Pagada

            // A quién se le debe o de quién se cobra (Proveedor, Corredor, Cliente)
            $table->morphs('entity');

            // Transacción que originó esta entrada (Opcional)
            $table->nullableMorphs('transaction');

            $table->date('due_date')->nullable(); // Fecha de vencimiento
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
