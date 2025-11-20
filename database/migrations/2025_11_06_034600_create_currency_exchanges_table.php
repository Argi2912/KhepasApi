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
        Schema::create('currency_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('number')->unique(); // ID único (Ej: CE-00001)

            // --- Participantes ---
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('admin_user_id')->constrained('users');               // Quien registró la operación
            $table->foreignId('broker_id')->nullable()->constrained('brokers');     // Opcional
            $table->foreignId('provider_id')->nullable()->constrained('providers'); // Opcional (Proveedor de liquidez)

            // --- Cuentas (Flujo de Dinero) ---
            $table->foreignId('from_account_id')->comment('Cuenta que envía (Sale dinero)')->constrained('accounts');
            $table->foreignId('to_account_id')->comment('Cuenta que recibe (Entra dinero)')->constrained('accounts');

            // --- Datos Financieros (MANUALES) ---
            $table->decimal('amount_sent', 14, 2)->comment('Monto que sale de la cuenta origen');
            $table->decimal('amount_received', 14, 2)->comment('Monto que entra a la cuenta destino');
            $table->decimal('exchange_rate', 16, 8)->comment('Tasa manual aplicada'); // Más decimales para precisión cripto
            $table->decimal('buy_rate', 16, 8)->comment('Tasa manual aplicada'); // Más decimales para precisión cripto


            // --- Comisiones (Montos Exactos para contabilidad) ---
            // Guardamos el monto directo para evitar errores de redondeo al recalcular
            $table->decimal('commission_total_amount', 14, 2)->default(0)->comment('Total descontado');
            $table->decimal('commission_provider_amount', 14, 2)->default(0)->comment('Parte para el proveedor');
            $table->decimal('commission_admin_amount', 14, 2)->default(0)->comment('Parte para la casa/admin');

            // --- Trazabilidad Externa ---
            $table->string('trader_info')->nullable()->comment('Ej: Pepito27 - Binance');
            $table->string('reference_id')->nullable()->comment('Hash o ID de la transacción externa');

            $table->string('status')->default('completed'); // completed, reversed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currency_exchanges');
    }
};
