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
        Schema::create('crop_progress_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crop_cycle_id');
            $table->date('log_date');
            $table->time('log_time')->nullable();
            $table->uuid('logged_by');
            $table->enum('health_rating', ['excellent', 'good', 'fair', 'poor', 'critical'])->default('good');
            $table->enum('growth_stage', ['germination', 'seedling', 'vegetative', 'flowering', 'fruiting', 'maturing', 'harvest_ready'])->nullable();
            $table->decimal('plant_height', 8, 2)->nullable();
            $table->integer('plant_count')->nullable();
            $table->decimal('coverage_percentage', 5, 2)->nullable();
            $table->json('pest_issues')->nullable();
            $table->json('disease_issues')->nullable();
            $table->json('nutrient_deficiencies')->nullable();
            $table->boolean('weed_pressure')->default(false);
            $table->enum('weed_level', ['none', 'light', 'moderate', 'heavy'])->default('none');
            $table->enum('weather_condition', ['sunny', 'partly_cloudy', 'cloudy', 'rainy', 'stormy', 'windy', 'foggy'])->nullable();
            $table->decimal('temperature', 5, 2)->nullable();
            $table->decimal('humidity', 5, 2)->nullable();
            $table->decimal('rainfall', 8, 2)->nullable();
            $table->boolean('irrigated')->default(false);
            $table->decimal('irrigation_amount', 8, 2)->nullable();
            $table->json('activities_done')->nullable();
            $table->json('inputs_applied')->nullable();
            $table->json('photos')->nullable();
            $table->text('observations')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('next_actions')->nullable();
            $table->decimal('yield_harvested', 8, 2)->nullable();
            $table->decimal('quality_grade', 3, 1)->nullable();
            $table->json('harvest_details')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('cascade');
            // Users remain in the public schema and are reachable through the
            // tenant search path, so this relationship is still enforceable.
            $table->foreign('logged_by')->references('id')->on('users')->onDelete('cascade');
            
            // Indexes
            $table->index(['crop_cycle_id', 'log_date']);
            $table->index('logged_by');
            $table->index('health_rating');
            $table->index('growth_stage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_progress_logs');
    }
};
