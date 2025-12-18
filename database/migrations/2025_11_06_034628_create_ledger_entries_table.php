<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('ledger_entries', function (Blueprint $table) {
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
            // Esto crea 'entity_type' y 'entity_id'
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->index(['entity_type', 'entity_id']);

            // Relación Polimórfica: ORIGEN (CurrencyExchange, DollarPurchase)
            // Esto crea 'transaction_type' y 'transaction_id' (Nullables)
            $table->nullableMorphs('transaction');

            $table->date('due_date')->nullable();
            $table->timestamps();
        });

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

        // Agregar campo de monto pagado y monto original en ledger_entries

    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_payments');

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['original_amount', 'paid_amount', 'pending_amount']);
            // Volver a status original
            $table->enum('status', ['pending', 'paid'])->default('pending');
        });
    }
};
