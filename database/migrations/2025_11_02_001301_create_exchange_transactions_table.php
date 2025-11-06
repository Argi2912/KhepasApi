<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * @return void
     */
    public function up()
    {
        Schema::create('exchange_transactions', function (Blueprint $table) {
            $table->id();
            
            // --- Relaciones ---
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->foreignId('exchange_rate_id')->constrained('exchange_rates')->onDelete('restrict');
            
            // --- Actores ---
            $table->foreignId('customer_user_id')->constrained('users')->onDelete('restrict');
            $table->foreignId('provider_user_id')->nullable()->constrained('users')->onDelete('restrict');
            $table->foreignId('broker_user_id')->nullable()->constrained('users')->onDelete('restrict'); // Para trazabilidad

            // --- Monedas ---
            $table->foreignId('currency_given_id')->constrained('currencies')->onDelete('restrict');
            $table->foreignId('currency_received_id')->constrained('currencies')->onDelete('restrict');
            
            // --- Montos Principales ---
            $table->decimal('amount_given', 15, 4); // Monto bruto entregado
            $table->decimal('net_amount_converted', 15, 4); // Monto neto después de comisiones (Base para tasa)
            $table->decimal('amount_received', 15, 4); // Monto final recibido en la otra divisa
            $table->decimal('effective_rate', 15, 6); // Tasa final (received / given)

            // --- Desglose de Comisiones (GASTOS) ---
            $table->decimal('commission_provider_percentage', 8, 4)->default(0);
            $table->decimal('commission_provider_amount', 15, 4)->default(0);
            $table->decimal('commission_platform_percentage', 8, 4)->default(0);
            $table->decimal('commission_platform_amount', 15, 4)->default(0);
            
            // --- Desglose de Comisiones (INGRESO) ---
            $table->decimal('commission_company_percentage', 8, 4)->default(0);
            $table->decimal('commission_company_amount', 15, 4)->default(0);

            // --- Totales ---
            $table->decimal('total_commission_expense_amount', 15, 4)->default(0); // Suma de gastos (provider + platform)

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('exchange_transactions');
    }
};