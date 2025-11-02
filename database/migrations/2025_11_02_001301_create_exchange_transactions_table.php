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
        Schema::create('exchange_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->onDelete('cascade');
            $table->foreignId('exchange_rate_id')->constrained('exchange_rates')->onDelete('restrict');
            $table->foreignId('currency_given_id')->constrained('currencies')->onDelete('restrict');
            $table->foreignId('currency_received_id')->constrained('currencies')->onDelete('restrict');
            $table->decimal('amount_given', 14, 2);
            $table->decimal('amount_received', 14, 2);
            $table->decimal('fee', 10, 2)->default(0);
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
        Schema::dropIfExists('exchange_transactions');
    }
};
