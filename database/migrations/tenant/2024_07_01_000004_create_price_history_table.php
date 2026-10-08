<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('price_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('crop_name'); // e.g., 'tomatoes', 'onions'
            $table->string('variety')->nullable(); // e.g., 'Roma', 'Cherry'
            $table->string('unit'); // kg, bunch, crate, bag
            $table->decimal('price_per_unit', 10, 2);
            $table->date('price_date');
            $table->string('market_location')->nullable();
            $table->enum('price_type', ['wholesale', 'retail', 'farmgate'])->default('farmgate');
            $table->uuid('sale_id')->nullable(); // Link to the sale that created this price point
            $table->enum('quality_grade', ['A', 'B', 'C', 'mixed', 'unknown'])->default('mixed');
            $table->decimal('quantity_sold', 12, 3)->nullable(); // Quantity sold at this price
            $table->string('season')->nullable(); // e.g., 'dry', 'wet', 'harvest'
            $table->text('market_conditions')->nullable(); // Description of market conditions
            $table->decimal('demand_level', 3, 2)->default(3.0); // Scale 1-5
            $table->decimal('supply_level', 3, 2)->default(3.0); // Scale 1-5
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('set null');
            $table->index(['crop_name', 'price_date']);
            $table->index(['price_date', 'market_location']);
            $table->index(['crop_name', 'variety', 'price_date']);
            $table->index('price_type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('price_history');
    }
};