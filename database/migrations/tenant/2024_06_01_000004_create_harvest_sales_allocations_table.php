<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('harvest_sales_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('harvest_id');
            $table->string('allocation_type'); // 'sale', 'storage', 'personal_use', 'loss'
            $table->decimal('allocated_quantity', 12, 3);
            $table->string('unit');
            $table->string('destination')->nullable(); // Buyer name, market, storage location
            $table->decimal('price_per_unit', 10, 2)->nullable(); // For sales
            $table->decimal('total_value', 12, 2)->nullable(); // Calculated total
            $table->date('allocation_date');
            $table->enum('status', ['pending', 'confirmed', 'delivered'])->default('pending');
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('harvest_id')->references('id')->on('harvests')->onDelete('cascade');
            $table->index(['harvest_id', 'allocation_type']);
            $table->index('allocation_date');
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('harvest_sales_allocations');
    }
};