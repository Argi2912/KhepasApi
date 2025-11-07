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
        Schema::table('accounts', function (Blueprint $table) {
            // Aseguramos que currency_code sea un STRING para códigos (USD, EUR, VES)
            $table->string('currency_code', 5)->change();
            
            // Si previamente tenías una tabla 'currencies' (que luego eliminamos),
            // aquí se añadiría la FK. Como no la tenemos, solo nos aseguramos del tipo de columna.
        });
        
        // 🚨 Crear un índice para optimizar las consultas de cuentas por divisa
        Schema::table('accounts', function (Blueprint $table) {
            $table->index('currency_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropIndex(['currency_code']);
            
            // Revertir el tipo de columna si fuera necesario (por ejemplo, a un TINYINT si lo usabas antes)
            // $table->tinyInteger('currency_code')->change(); 
        });
    }
};
