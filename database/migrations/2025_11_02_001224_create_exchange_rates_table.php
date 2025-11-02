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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('from_currency_id')->constrained('currencies')->onDelete('restrict');
            $table->foreignId('to_currency_id')->constrained('currencies')->onDelete('restrict');
            $table->decimal('rate', 10, 6);
            $table->date('date');
            $table->timestamps();
            
            // LÍNEA MODIFICADA: Especificamos un nombre más corto (ej: 'rate_unique')
            $table->unique(
                ['tenant_id', 'from_currency_id', 'to_currency_id', 'date'],
                'rate_unique_check' // Nombre corto para el índice
            );
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('exchange_rates');
    }
};
