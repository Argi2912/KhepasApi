<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable(); 
            $table->string('name');
            $table->string('alias')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            
            // 👇 ESTE SÍ LO DEJAMOS (Era el del error original)
            $table->decimal('available_balance', 20, 4)->default(0);

            // ❌ HE BORRADO 'interest_rate' y 'payout_day' DE AQUÍ
            // para que no choquen con tu otro archivo.

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};