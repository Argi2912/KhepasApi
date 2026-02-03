<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_active')->default(false)->change();
            $table->string('binance_merchant_trade_no')->nullable()->unique()->after('name');
            $table->string('binance_prepay_id')->nullable()->after('binance_merchant_trade_no');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->change();
            $table->dropColumn(['binance_merchant_trade_no', 'binance_prepay_id']);
        });
    }
};
