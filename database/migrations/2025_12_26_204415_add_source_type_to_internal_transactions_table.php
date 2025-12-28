<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            // Agregamos la columna source_type
            if (!Schema::hasColumn('internal_transactions', 'source_type')) {
                $table->string('source_type')->default('account')->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            $table->dropColumn('source_type');
        });
    }
};
