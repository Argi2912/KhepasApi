<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            // 1. Agregar user_id (Quién registró)
            if (!Schema::hasColumn('ledger_entries', 'user_id')) {
                $table->foreignId('user_id')
                      ->nullable()
                      ->after('tenant_id') // Para mantener orden visual
                      ->constrained('users')
                      ->onDelete('set null'); 
            }

            // 2. Agregar currency_id (Para el filtro de moneda)
            if (!Schema::hasColumn('ledger_entries', 'currency_id')) {
                $table->foreignId('currency_id')
                      ->nullable()
                      ->after('amount')
                      ->constrained('currencies'); 
            }
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            // Eliminar columnas si revertimos
            if (Schema::hasColumn('ledger_entries', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
            if (Schema::hasColumn('ledger_entries', 'currency_id')) {
                $table->dropForeign(['currency_id']);
                $table->dropColumn('currency_id');
            }
        });
    }
};