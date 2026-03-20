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
        Schema::create('support_messages', function (Blueprint $row) {
            $row->id();
            $row->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade'); // El usuario que inició el ticket
            $row->foreignId('sender_id')->nullable()->constrained('users')->onDelete('cascade'); // El que escribe el mensaje actual
            $row->foreignId('tenant_id')->nullable()->constrained('tenants')->onDelete('cascade');
            $row->string('subject')->nullable();
            $row->text('body');
            $row->boolean('is_read')->default(false);
            $row->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
