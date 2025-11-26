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
        Schema::create('brokers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            // 🚨 CAMBIO: Datos propios del Broker (Ya no usa user_id)
            $table->string('name'); 
            $table->string('email')->nullable();
            $table->string('document_id')->nullable(); // Cédula, RIF o Pasaporte
            
            $table->decimal('default_commission_rate', 5, 2)->default(0); // Comisión %
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brokers');
    }
};
