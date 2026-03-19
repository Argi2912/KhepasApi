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
            $table->foreignId('client_id')->nullable()->change();
            $table->foreignId('to_account_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currency_exchanges', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable(false)->change();
            $table->foreignId('to_account_id')->nullable(false)->change();
        });
    }
};
