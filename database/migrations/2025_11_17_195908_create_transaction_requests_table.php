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
        Schema::create('transaction_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Cliente que hace la solicitud
            $table->foreignId('client_id')->constrained('clients');
            
            // Detalles de la solicitud
            $table->string('type'); // 'exchange' (Cambio) o 'withdrawal' (Retiro/Pago)
            $table->string('source_origin')->nullable(); // Ej: "Banco Banesco", "Zelle" (Texto libre o ID)
            $table->string('destination_target')->nullable(); // Ej: "Binance USDT"
            
            $table->decimal('amount', 14, 2); // Monto que el cliente dice que envió o quiere cambiar
            $table->string('currency_code', 5); // Moneda del monto (USD, VES)
            
            $table->string('status')->default('pending'); // pending, processed, rejected
            $table->text('notes')->nullable(); // Notas del cliente

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_requests');
    }
};
