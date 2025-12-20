<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            // 👇 ESTA LÍNEA MÁGICA CREA: 'entity_type' Y 'entity_id'
            $table->nullableMorphs('entity'); 
            
            // Asegurémonos de que tienes estos también, por si acaso faltan:
            if (!Schema::hasColumn('internal_transactions', 'dueño')) {
                $table->string('dueño')->nullable()->after('description');
            }
            if (!Schema::hasColumn('internal_transactions', 'person_name')) {
                $table->string('person_name')->nullable()->after('dueño');
            }
        });
    }

    public function down(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            $table->dropMorphs('entity'); // Elimina entity_type y entity_id
            $table->dropColumn(['dueño', 'person_name']);
        });
    }
};