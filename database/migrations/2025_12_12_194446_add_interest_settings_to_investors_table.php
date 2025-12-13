<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::table('investors', function (Blueprint $table) {
        $table->decimal('interest_rate', 5, 2)->default(0)->after('is_active'); // Ej: 5.00%
        $table->integer('payout_day')->default(30)->after('interest_rate'); // Ej: Día 30
        $table->date('last_interest_date')->nullable()->after('payout_day'); // Para evitar duplicados
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investors', function (Blueprint $table) {
            //
        });
    }
};
