<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            $table->string('currency_code', 5)->nullable()->after('amount')->index();
            $table->foreign('currency_code')->references('currency_code')->on('accounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            $table->dropColumn('currency_code');
        });
    }
};
