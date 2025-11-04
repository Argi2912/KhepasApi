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
        Schema::table('cashes', function (Blueprint $table) {
            $table->foreignId('currency_id')
                  ->nullable() // O 'required()' si todas las cajas deben tenerla
                  ->after('account_id')
                  ->constrained('currencies')
                  ->onDelete('restrict'); // No eliminar divisa si está en uso en una caja
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('cashes', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });
    }
};
