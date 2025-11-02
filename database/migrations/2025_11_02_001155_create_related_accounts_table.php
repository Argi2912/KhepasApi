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
        Schema::create('related_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_transaction_id')->constrained('transactions')->onDelete('cascade'); // La transacción de pago o cobro
            $table->foreignId('related_transaction_id')->nullable()->constrained('transactions')->onDelete('restrict'); // La CXC/CXP original
            $table->foreignId('account_to_affect_id')->constrained('accounts')->onDelete('restrict'); // La cuenta contable afec
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('related_accounts');
    }
};
