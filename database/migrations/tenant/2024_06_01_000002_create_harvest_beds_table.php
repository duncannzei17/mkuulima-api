<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('harvest_beds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('harvest_id');
            $table->string('bed_name'); // e.g., "Bed A1", "Plot 3-B"
            $table->decimal('quantity', 12, 3);
            $table->string('unit');
            $table->enum('grade', ['marketable', 'non_marketable', 'A', 'B', 'C', 'reject'])->nullable();
            $table->decimal('area_harvested', 10, 2)->nullable(); // In square meters
            $table->decimal('yield_per_sqm', 10, 3)->nullable(); // Calculated yield per square meter
            $table->text('bed_notes')->nullable();
            $table->json('bed_conditions')->nullable(); // Specific conditions for this bed
            $table->timestamps();

            $table->foreign('harvest_id')->references('id')->on('harvests')->onDelete('cascade');
            $table->index('harvest_id');
            $table->index('bed_name');
        });
    }

    public function down()
    {
        Schema::dropIfExists('harvest_beds');
    }
};