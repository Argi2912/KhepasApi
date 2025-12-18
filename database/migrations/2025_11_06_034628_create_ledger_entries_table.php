<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. CORRECCIÓN PRINCIPAL: Usar 'create' en lugar de 'table'
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            // Relación con Tenant
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');

            $table->string('description');

            // Montos Financieros
            $table->decimal('amount', 14, 2);           // Monto actual/principal
            $table->decimal('original_amount', 14, 2);  // Monto inicial de la deuda
            $table->decimal('paid_amount', 14, 2)->default(0); // Lo que ya se pagó
            $table->decimal('pending_amount', 14, 2);   // Lo que falta (calculado)

            // Moneda (Según tu tabla es 'currency_type' varchar(5))
            $table->string('currency_type', 5)->nullable();

            // Tipos y Estados
            $table->enum('type', ['payable', 'receivable']);
            $table->enum('status', ['pending', 'partially_paid', 'paid'])->default('pending');

            // Relación Polimórfica: A QUIÉN (Provider, Broker, Client)
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->index(['entity_type', 'entity_id']);

            // Relación Polimórfica: ORIGEN (CurrencyExchange, DollarPurchase)
            $table->nullableMorphs('transaction');

            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        // Creación de la tabla de pagos (esto ya estaba bien, usa 'create')
        Schema::create('ledger_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_entry_id')->constrained('ledger_entries')->onDelete('cascade');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('amount', 14, 2);
            $table->timestamp('payment_date')->useCurrent();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['ledger_entry_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        // 2. CORRECCIÓN EN DOWN: Borrar las tablas completas
        Schema::dropIfExists('ledger_payments');
        Schema::dropIfExists('ledger_entries');
    }
};
