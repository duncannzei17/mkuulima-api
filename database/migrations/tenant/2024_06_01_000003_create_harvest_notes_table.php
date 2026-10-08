<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('harvest_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('harvest_id');
            $table->text('note');
            $table->enum('note_type', [
                'general',
                'health_condition', 
                'pest_issue',
                'weather_impact',
                'quality_issue',
                'anomaly',
                'improvement'
            ])->default('general');
            $table->uuid('created_by');
            $table->json('metadata')->nullable(); // Additional structured data
            $table->boolean('is_critical')->default(false); // For important observations
            $table->timestamps();

            $table->foreign('harvest_id')->references('id')->on('harvests')->onDelete('cascade');
            $table->index('harvest_id');
            $table->index('note_type');
            $table->index('is_critical');
        });
    }

    public function down()
    {
        Schema::dropIfExists('harvest_notes');
    }
};