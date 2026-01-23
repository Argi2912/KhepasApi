<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            // 1. Eliminamos la llave foránea actual
            $table->dropForeign(['account_id']);
            
            // 2. Modificamos la columna para que acepte NULL
            $table->unsignedBigInteger('account_id')->nullable()->change();
            
            // 3. Volvemos a crear la llave foránea (opcional, pero recomendada para integridad si hay dato)
            $table->foreign('account_id')
                  ->references('id')
                  ->on('accounts')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            // Revertir los cambios (volver a obligatorio)
            $table->dropForeign(['account_id']);
            $table->unsignedBigInteger('account_id')->nullable(false)->change();
            $table->foreign('account_id')->references('id')->on('accounts')->onDelete('cascade');
        });
    }
};