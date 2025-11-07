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
        Schema::create('dollar_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('number')->unique(); // Número de solicitud

            // Participantes (de la imagen)
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('broker_id')->constrained('brokers');
            $table->foreignId('provider_id')->constrained('providers');
            $table->foreignId('admin_user_id')->constrained('users');
            $table->foreignId('platform_account_id')->constrained('accounts'); // Plataforma de donde sale el dinero

                                                       // Montos (de la imagen)
            $table->decimal('amount_received', 14, 2); // Ej: 2500 (en VES, por ej.)
            $table->decimal('buy_rate', 14, 2);        // Tasa de Compra (Ej: 230)
            $table->decimal('received_rate', 14, 2);   // Tasa Recibida (Ej: 240)
            $table->decimal('commission_charged_pct', 5, 2)->comment('Comisión Cobrada %');
            $table->decimal('commission_provider_pct', 5, 2)->comment('Comisión Proveedor %');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dollar_purchases');
    }
};
