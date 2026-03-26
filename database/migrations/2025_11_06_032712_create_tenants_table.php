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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(false); // Cambiado a false por defecto hasta que paguen

            // Campos para suscripción (Nuevos)
            $table->string('plan_name')->nullable();
            $table->decimal('plan_price', 8, 2)->default(0);
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('paypal_order_id')->nullable(); // Para rastrear el pago actual

            // Campos para otros métodos (Binance/Stripe)
            $table->string('external_payment_id')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
