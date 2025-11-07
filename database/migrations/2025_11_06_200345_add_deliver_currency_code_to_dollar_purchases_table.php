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
        Schema::table('dollar_purchases', function (Blueprint $table) {
            $table->string('deliver_currency_code', 5)->default('USD')->after('platform_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dollar_purchases', function (Blueprint $table) {
            $table->dropColumn('deliver_currency_code');
        });
    }
};
