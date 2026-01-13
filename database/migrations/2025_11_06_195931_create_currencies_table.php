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
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            
            // 1. CORRECCIÓN: Se elimina ->unique() de aquí para evitar el bloqueo global
            $table->string('code', 5); 
            $table->string('name', 50); 
            
            $table->unsignedBigInteger('tenant_id')->nullable(); 
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->timestamps();
            
            $table->index('tenant_id');

            // 2. NUEVA REGLA: La unicidad depende del tenant.
            // Esto permite que el Tenant A tenga "USD" y el Tenant B también.
            $table->unique(['tenant_id', 'code']); 
            $table->unique(['tenant_id', 'name']); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};