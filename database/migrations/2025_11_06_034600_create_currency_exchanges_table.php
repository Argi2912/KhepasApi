<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('number')->unique();

            // --- Participantes ---
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('admin_user_id')->constrained('users');
            
            // Opcionales
            $table->foreignId('broker_id')->nullable()->constrained('brokers');
            $table->foreignId('provider_id')->nullable()->constrained('providers');

            // ID Plataforma (sin restricción estricta para evitar errores de orden)
            $table->unsignedBigInteger('platform_id')->nullable()->comment('ID Plataforma');

            // --- Cuentas ---
            // CORRECCIÓN 1: Ahora permite NULOS (para cuando paga un Inversionista)
            $table->foreignId('from_account_id')->nullable()->constrained('accounts');
            
            $table->foreignId('to_account_id')->constrained('accounts');

            // --- Datos Financieros ---
            $table->decimal('amount_sent', 14, 2);
            $table->decimal('amount_received', 14, 2);
            $table->decimal('exchange_rate', 16, 8);
            $table->decimal('buy_rate', 16, 8)->nullable();

            // --- Comisiones ---
            $table->decimal('commission_total_amount', 14, 2)->default(0);
            $table->decimal('commission_provider_amount', 14, 2)->default(0);
            $table->decimal('commission_admin_amount', 14, 2)->default(0);
            $table->decimal('commission_broker_amount', 14, 2)->default(0);

            // --- NUEVOS CAMPOS (INVERSIONISTA) ---
            // CORRECCIÓN 2: Agregamos los campos que faltaban
            $table->string('capital_type')->default('own'); // 'own' o 'investor'
            $table->foreignId('investor_id')->nullable()->constrained('investors')->onDelete('set null');
            $table->decimal('investor_profit_pct', 8, 2)->default(0);
            $table->decimal('investor_profit_amount', 14, 2)->default(0);

            // --- Trazabilidad ---
            $table->string('trader_info')->nullable();
            $table->string('reference_id')->nullable();

            $table->string('status')->default('completed');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_exchanges');
    }
};