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
    Schema::table('providers', function (Blueprint $table) {
        // Agregamos la "Billetera" al proveedor.
        // 20 dígitos en total, 2 decimales. Empieza en 0.
        $table->decimal('available_balance', 20, 2)->default(0)->after('is_active');
    });
}

public function down(): void
{
    Schema::table('providers', function (Blueprint $table) {
        $table->dropColumn('available_balance');
    });
}
};
