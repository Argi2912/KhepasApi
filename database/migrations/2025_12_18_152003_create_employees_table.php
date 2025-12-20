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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            
            // Datos Personales
            $table->string('name');
            $table->string('identification_doc')->nullable(); // Cédula/DNI
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable(); // Cargo (Ej: Cajera)
            
            // Datos Financieros (Nómina)
            $table->decimal('salary_amount', 14, 2); // Cuánto gana
            $table->string('currency_code', 3)->default('USD'); // En qué moneda (USD, VES)
            
            // Configuración de Pago
            $table->enum('payment_frequency', ['weekly', 'biweekly', 'monthly'])->default('biweekly'); 
            $table->integer('payment_day_1')->default(15); // Primer día de pago
            $table->integer('payment_day_2')->nullable()->default(30); // Segundo día de pago (si aplica)
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
