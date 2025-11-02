<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('transaction_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->foreignId('account_id')->constrained('accounts')->onDelete('restrict');
            $table->decimal('amount', 14, 2); // Monto POSITIVO/NEGATIVO (siempre se guarda como positivo aquí)
            $table->boolean('is_debit'); // TRUE para Debito, FALSE para Credito
            $table->timestamps();

            $table->unique(['transaction_id', 'account_id']); // Para asegurar unicidad en el asiento
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('transaction_details');
    }
};
