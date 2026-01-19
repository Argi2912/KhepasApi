<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Desactivar seguridad de llaves foráneas para evitar conflictos al crear
        Schema::disableForeignKeyConstraints();

        // 1. CREAR LA TABLA LEDGER_ENTRIES (Si no existe)
        if (!Schema::hasTable('ledger_entries')) {
            Schema::create('ledger_entries', function (Blueprint $table) {
                $table->id();

                // Columnas requeridas según tu error SQL
                $table->unsignedBigInteger('tenant_id')->nullable();

                // Para transaction_type y transaction_id
                $table->nullableMorphs('transaction');

                // Para entity_type y entity_id (Cliente, Empleado, etc)
                $table->nullableMorphs('entity');

                $table->string('type'); // receivable / payable

                // Status y montos
                $table->string('status')->default('pending'); // Usamos string para evitar problemas de enum
                $table->decimal('amount', 14, 2);

                // Las columnas que te faltaban antes
                $table->decimal('original_amount', 14, 2);
                $table->decimal('paid_amount', 14, 2)->default(0);
                $table->decimal('pending_amount', 14, 2);

                $table->string('currency_code', 19)->default('USD');
                $table->text('description')->nullable();
                $table->date('due_date')->nullable();

                $table->timestamps();
            });
        }

        // 2. CREAR LA TABLA LEDGER_PAYMENTS
        if (!Schema::hasTable('ledger_payments')) {
            Schema::create('ledger_payments', function (Blueprint $table) {
                $table->id();

                // Relación con la tabla de arriba
                $table->foreignId('ledger_entry_id')
                    ->constrained('ledger_entries')
                    ->onDelete('cascade');

                $table->foreignId('account_id')->constrained('accounts');
                $table->foreignId('user_id')->constrained('users');

                $table->decimal('amount', 14, 2);
                $table->timestamp('payment_date')->useCurrent();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index(['ledger_entry_id', 'payment_date']);
            });
        }

        // Reactivar seguridad
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('ledger_payments');
        Schema::dropIfExists('ledger_entries');
        Schema::enableForeignKeyConstraints();
    }
};
