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
        Schema::table('internal_transactions', function (Blueprint $table) {
            $table->string('currency_code', 3)->after('amount');
            $table->foreign('currency_code')->references('code')->on('currencies')->onDelete('restrict');
            $table->index('currency_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            //
        });
    }
};
