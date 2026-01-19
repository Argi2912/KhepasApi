<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up()
{
    Schema::table('ledger_entries', function (Blueprint $table) {
        // El método ->change() modifica la columna sin borrar datos
        $table->string('currency_code', 10)->nullable()->change();
    });

    // Repite para otras tablas si es necesario
    Schema::table('accounts', function (Blueprint $table) {
        $table->string('currency_code', 10)->change();
    });
}

public function down()
{
    // Esto revierte el cambio si hicieras rollback (vuelve a 3 caracteres)
    Schema::table('ledger_entries', function (Blueprint $table) {
        $table->string('currency_code', 3)->nullable()->change();
    });

    Schema::table('accounts', function (Blueprint $table) {
        $table->string('currency_code', 3)->change();
    });
}
};
