<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 18, 5);
            $table->decimal('commission_charged', 18, 5);
            $table->decimal('supplier_commission', 18, 5);
            $table->decimal('admin_commission', 18, 5);

            $table->unsignedBigInteger('source_currency_id');
            $table->foreign('source_currency_id')->references('id')->on('currencies');
            $table->unsignedBigInteger('destination_currency_id');
            $table->foreign('destination_currency_id')->references('id')->on('currencies');

            $table->decimal('destination_amount', 18, 5)->nullable();
            $table->decimal('applied_exchange_rate', 18, 8)->nullable();

            $table->unsignedBigInteger('request_type_id');
            $table->foreign('request_type_id')->references('id')->on('request_types');

            $table->enum('status', ['pending', 'approved', 'processing', 'completed', 'rejected', 'cancelled'])
                  ->default('pending')
                  ->index(); 
            $table->text('rejection_reason')->nullable(); 
            $table->unsignedBigInteger('client_id');
            $table->foreign('client_id')->references('id')->on('users');
            $table->unsignedBigInteger('broker_id');
            $table->foreign('broker_id')->references('id')->on('users');
            $table->unsignedBigInteger('supplier_id');
            $table->foreign('supplier_id')->references('id')->on('users');
            $table->unsignedBigInteger('admin_id');
            $table->foreign('admin_id')->references('id')->on('users');
            
            $table->unsignedBigInteger('source_platform_id');
            $table->foreign('source_platform_id')->references('id')->on('platforms');
            $table->unsignedBigInteger('destination_platform_id');
            $table->foreign('destination_platform_id')->references('id')->on('platforms');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('requests');
    }
};
