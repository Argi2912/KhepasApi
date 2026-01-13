<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            
            // 1. Verificamos si YA existen las columnas morph (entity_type y entity_id)
            // Si NO existen, las creamos. Si ya existen (porque las pusiste en la migración anterior), no hace nada.
            if (!Schema::hasColumn('internal_transactions', 'entity_type')) {
                $table->nullableMorphs('entity'); 
            }
            
            // 2. Verificamos 'dueño'
            if (!Schema::hasColumn('internal_transactions', 'dueño')) {
                $table->string('dueño')->nullable()->after('description');
            }

            // 3. Verificamos 'person_name'
            if (!Schema::hasColumn('internal_transactions', 'person_name')) {
                $table->string('person_name')->nullable()->after('dueño');
            }
        });
    }

    public function down(): void
    {
        Schema::table('internal_transactions', function (Blueprint $table) {
            // En el down también protegemos para evitar errores si las columnas no existen
            if (Schema::hasColumn('internal_transactions', 'entity_type')) {
                $table->dropMorphs('entity');
            }
            if (Schema::hasColumn('internal_transactions', 'dueño')) {
                $table->dropColumn(['dueño', 'person_name']);
            }
        });
    }
};