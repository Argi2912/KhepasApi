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
        Schema::create('internal_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Responsable del movimiento
            $table->foreignId('user_id')->constrained('users'); 
            
            // Cuenta afectada
            $table->foreignId('account_id')->constrained('accounts');
            
            // Detalles del movimiento
            $table->enum('type', ['income', 'expense']); // Ingreso o Egreso
            $table->string('category')->nullable(); // Ej: "Sueldos", "Servicios", "Ajuste de Caja"
            $table->decimal('amount', 14, 2); // Monto exacto
            
            $table->text('description')->nullable(); // "Pago de luz oficina"
            $table->timestamp('transaction_date')->useCurrent(); // Fecha real del movimiento
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internal_transactions');
    }
};
