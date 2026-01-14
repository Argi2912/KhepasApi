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

            // Tenant ya tenía cascade, perfecto.
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // CORRECCIÓN 1: Agregado onDelete('cascade')
            // Permite borrar el usuario sin que las transacciones bloqueen la operación
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            // CORRECCIÓN 2: Agregado onDelete('cascade')
            // Permite borrar la cuenta bancaria sin errores de integridad
            $table->foreignId('account_id')->constrained('accounts')->onDelete('cascade'); 

            $table->enum('type', ['income', 'expense', 'info']); // Agregué 'info' por si acaso (para interés compuesto)
            $table->string('category')->nullable();
            $table->decimal('amount', 14, 2);
            $table->text('description')->nullable();
            $table->timestamp('transaction_date')->useCurrent();

            // --- CAMPOS FALTANTES QUE CAUSABAN EL ERROR ---
            $table->string('source_type')->default('account'); // account, investor, provider
            $table->nullableMorphs('entity'); // Crea entity_type y entity_id automáticamente

            $table->text('dueño')->nullable();
            $table->text('person_name')->nullable();

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