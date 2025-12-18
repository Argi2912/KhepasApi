<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->decimal('original_amount', 14, 2)->after('amount'); // Monto original
            $table->decimal('paid_amount', 14, 2)->default(0)->after('original_amount'); // Total abonado
            $table->decimal('pending_amount', 14, 2)->after('paid_amount'); // Calculado
            $table->enum('status', ['pending', 'partially_paid', 'paid'])->default('pending')->change();
        });

        Schema::create('ledger_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_entry_id')->constrained('ledger_entries')->onDelete('cascade');
            $table->foreignId('account_id')->constrained('accounts');
            $table->foreignId('user_id')->constrained('users');
            $table->decimal('amount', 14, 2);
            $table->timestamp('payment_date')->useCurrent();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['ledger_entry_id', 'payment_date']);
        });

        // Agregar campo de monto pagado y monto original en ledger_entries

    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_payments');

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['original_amount', 'paid_amount', 'pending_amount']);
            // Volver a status original
            $table->enum('status', ['pending', 'paid'])->default('pending');
        });
    }
};
