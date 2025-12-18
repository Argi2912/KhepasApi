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
        Schema::table('currency_exchanges', function (Blueprint $table) {
            $table->string('currency_sent', 3)->nullable()->after('amount_sent');
            $table->string('currency_received', 3)->nullable()->after('amount_received');
            $table->foreign('currency_sent')->references('code')->on('currencies')->onDelete('restrict');
            $table->foreign('currency_received')->references('code')->on('currencies')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currency_exchanges', function (Blueprint $table) {
            $table->dropForeign(['currency_sent']);
            $table->dropForeign(['currency_received']);
            $table->dropColumn('currency_sent');
            $table->dropColumn('currency_received');
        });
    }
};
