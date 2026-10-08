<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('crop_name');
            $table->string('variety')->nullable();
            $table->string('unit');
            $table->decimal('price_per_unit', 10, 2);
            $table->date('price_date');
            $table->string('market_location')->nullable();
            $table->enum('price_type', ['wholesale', 'retail', 'farmgate'])->default('farmgate');
            $table->uuid('sale_id')->nullable();
            $table->enum('quality_grade', ['A', 'B', 'C', 'mixed', 'unknown'])->default('mixed');
            $table->decimal('quantity_sold', 12, 3)->nullable();
            $table->string('season')->nullable();
            $table->text('market_conditions')->nullable();
            $table->decimal('demand_level', 3, 2)->default(3.0);
            $table->decimal('supply_level', 3, 2)->default(3.0);
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
            $table->index(['crop_name', 'price_date']);
            $table->index(['price_date', 'market_location']);
            $table->index(['crop_name', 'variety', 'price_date']);
            $table->index('price_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_history');
    }
};
