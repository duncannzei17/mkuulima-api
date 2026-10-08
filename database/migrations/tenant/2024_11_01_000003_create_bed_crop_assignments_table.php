<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean up partially-created table on reruns
        Schema::dropIfExists('bed_crop_assignments');

        Schema::create('bed_crop_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bed_id')->index();
            $table->uuid('crop_cycle_id')->index();
            $table->uuid('farm_id')->index();
            
            // Assignment Timeline
            $table->date('start_date')->index();
            $table->date('expected_end_date')->nullable()->index();
            $table->date('actual_end_date')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            
            // Crop Details (denormalized for performance)
            $table->string('crop_name', 100)->index();
            $table->string('variety', 100)->nullable();
            $table->decimal('expected_yield_kg', 8, 2)->default(0);
            $table->decimal('actual_yield_kg', 8, 2)->default(0);
            
            // Bed Usage
            $table->decimal('area_used_sqm', 10, 2)->nullable(); // area of bed used for this crop
            $table->integer('area_percentage_used')->default(100); // % of bed used (1-100)
            $table->integer('plant_count')->nullable(); // number of plants
            $table->decimal('plant_density_per_sqm', 8, 2)->nullable(); // plants per square meter
            
            // Performance Metrics
            $table->decimal('yield_per_sqm', 8, 2)->default(0); // kg per square meter
            $table->decimal('yield_efficiency_score', 5, 2)->default(0); // 0-100 compared to expected
            $table->enum('performance_rating', ['poor', 'below_average', 'average', 'good', 'excellent'])->nullable();
            
            // Cost Tracking (cached from related modules)
            $table->decimal('total_labour_cost', 10, 2)->default(0);
            $table->decimal('total_input_cost', 10, 2)->default(0);
            $table->decimal('total_harvest_cost', 10, 2)->default(0);
            $table->decimal('total_cost', 10, 2)->default(0); // sum of all costs
            $table->decimal('cost_per_kg', 8, 2)->default(0); // total cost / yield
            
            // Revenue & Profitability (if sold)
            $table->decimal('total_revenue', 10, 2)->default(0);
            $table->decimal('net_profit', 10, 2)->default(0);
            $table->decimal('profit_margin_percentage', 5, 2)->default(0);
            $table->decimal('roi_percentage', 8, 2)->default(0); // return on investment
            
            // Health & Quality Tracking
            $table->decimal('health_score_start', 5, 2)->default(100); // bed health when crop started
            $table->decimal('health_score_end', 5, 2)->default(100); // bed health when crop ended
            $table->decimal('health_impact', 6, 2)->default(0); // change in health score
            $table->integer('total_observations')->default(0); // observations during this cycle
            $table->integer('critical_issues')->default(0); // critical observations
            
            // Timeline Tracking
            $table->integer('days_to_harvest')->nullable(); // actual days from plant to harvest
            $table->integer('expected_days_to_harvest')->nullable(); // planned days
            $table->integer('timeline_variance_days')->default(0); // actual - expected
            $table->enum('timeline_performance', ['early', 'on_time', 'delayed'])->nullable();
            
            // Quality Assessment
            $table->enum('harvest_quality', ['poor', 'fair', 'good', 'excellent'])->nullable();
            $table->decimal('marketable_yield_percentage', 5, 2)->default(100); // % of yield that was marketable
            $table->decimal('waste_percentage', 5, 2)->default(0); // % lost to waste/spoilage
            
            // Seasonal Context
            $table->enum('season', ['dry', 'wet', 'transition'])->nullable();
            $table->json('weather_summary')->nullable(); // summary of weather during crop cycle
            $table->boolean('irrigation_used')->default(false);
            $table->decimal('water_usage_liters', 10, 2)->nullable(); // if tracked
            
            // Assignment Status
            $table->enum('assignment_status', [
                'planned', 'planted', 'growing', 'harvesting', 'completed', 'failed', 'abandoned'
            ])->default('planned')->index();
            $table->text('completion_notes')->nullable();
            $table->timestamp('status_updated_at')->nullable();
            
            // User Tracking
            $table->uuid('assigned_by')->index();
            $table->uuid('completed_by')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['bed_id', 'is_active']);
            $table->index(['bed_id', 'start_date']);
            $table->index(['crop_cycle_id', 'bed_id']);
            $table->index(['farm_id', 'start_date']);
            $table->index(['farm_id', 'crop_name']);
            $table->index(['farm_id', 'assignment_status']);
            $table->index(['yield_efficiency_score']);
            $table->index(['performance_rating']);
            
            // Prevent multiple active assignments for same bed
            $table->unique(['bed_id', 'crop_cycle_id'], 'unique_bed_crop_assignment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_crop_assignments');
    }
};
