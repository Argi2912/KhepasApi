<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('currency_exchanges', function (Blueprint $table) {
            // Creamos la columna, por defecto 'exchange' para evitar nulos
            $table->string('type', 20)->default('exchange')->after('number')->index();
        });

        // --- MIGRACIÓN DE DATOS EXISTENTES ---
        // Si tiene buy_rate, asumimos que era una 'purchase' (Compra)
        // Esto mantiene la compatibilidad con tus datos actuales.
        DB::table('currency_exchanges')
            ->whereNotNull('buy_rate')
            ->where('buy_rate', '>', 0)
            ->update(['type' => 'purchase']);
    }

    public function down(): void
    {
        Schema::table('currency_exchanges', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
