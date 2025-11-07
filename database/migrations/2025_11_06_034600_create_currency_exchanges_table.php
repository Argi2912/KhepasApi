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
            $table->string('number')->unique(); // Número de solicitud

            // Participantes (de la imagen)
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('broker_id')->constrained('brokers');
            $table->foreignId('provider_id')->constrained('providers');
            $table->foreignId('admin_user_id')->constrained('users'); // El admin que registró

            // Cuentas (de la imagen)
            $table->foreignId('from_account_id')->comment('Origen')->constrained('accounts');
            $table->foreignId('to_account_id')->comment('Destino')->constrained('accounts');

            // Montos (de la imagen)
            $table->decimal('amount_received', 14, 2);
            $table->decimal('commission_charged_pct', 5, 2)->comment('Comisión Cobrada %');
            $table->decimal('commission_provider_pct', 5, 2)->comment('Comisión Proveedor %');
            $table->decimal('commission_admin_pct', 5, 2)->comment('Comisión Admin %');

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
