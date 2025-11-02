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
        Schema::create('cash_closures', function (Blueprint $table) {
            $table->id();
            // Declarar solo las columnas como unsignedBigInteger
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('cash_id');
            $table->unsignedBigInteger('user_id');

            $table->timestamp('start_date');
            $table->timestamp('end_date')->nullable();
            $table->decimal('initial_balance', 14, 2);
            $table->decimal('final_balance', 14, 2)->nullable();
            $table->decimal('difference', 14, 2)->nullable();
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
        Schema::dropIfExists('cash_closures');
    }
};
