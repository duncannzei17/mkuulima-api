<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->string('crop_name');
            $table->string('variety')->nullable();
            $table->enum('status', [
                'planning', 'planted', 'growing', 'harvesting', 'completed', 'failed', 'abandoned'
            ])->default('planning');
            $table->date('planting_date')->nullable();
            $table->date('expected_harvest_date')->nullable();
            $table->date('actual_harvest_date')->nullable();
            $table->decimal('expected_yield_kg', 12, 2)->default(0);
            $table->decimal('actual_yield_kg', 12, 2)->default(0);
            $table->integer('bed_count')->nullable();
            $table->timestamps();

            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            $table->index(['farm_id', 'status']);
            $table->index(['farm_id', 'planting_date']);
            $table->index(['farm_id', 'actual_harvest_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_cycles');
    }
};
