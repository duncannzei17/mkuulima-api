<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->string('name', 100)->index(); // P1, P2, Bed 1, Block A, etc.
            $table->text('description')->nullable();
            
            // Physical Properties
            $table->enum('bed_type', ['raised_bed', 'flat_bed', 'row', 'block', 'greenhouse', 'nursery'])->default('flat_bed')->index();
            $table->decimal('size_length', 8, 2)->nullable(); // in meters
            $table->decimal('size_width', 8, 2)->nullable(); // in meters  
            $table->decimal('size_area', 10, 2)->nullable(); // calculated area in square meters
            $table->enum('area_unit', ['square_meters', 'acres', 'hectares'])->default('square_meters');
            
            // Layout & Organization
            $table->integer('layout_order')->default(0)->index(); // for grid display ordering
            $table->integer('layout_row')->nullable(); // grid row position
            $table->integer('layout_column')->nullable(); // grid column position
            $table->decimal('gps_latitude', 10, 8)->nullable();
            $table->decimal('gps_longitude', 11, 8)->nullable();
            
            // Status & Health
            $table->enum('status', [
                'empty', 'planted', 'under_maintenance', 'issues_detected', 
                'ready_to_harvest', 'harvesting', 'completed', 'fallow'
            ])->default('empty')->index();
            $table->decimal('health_score', 5, 2)->default(100.00); // 0-100 scale
            $table->timestamp('health_score_updated_at')->nullable();
            
            // Soil & Environmental Data
            $table->enum('soil_type', ['clay', 'loam', 'sandy', 'silt', 'mixed'])->nullable();
            $table->decimal('soil_ph', 4, 2)->nullable(); // pH level
            $table->enum('drainage_quality', ['poor', 'fair', 'good', 'excellent'])->default('good');
            $table->enum('sun_exposure', ['full_sun', 'partial_sun', 'shade', 'partial_shade'])->default('full_sun');
            $table->boolean('has_irrigation')->default(false);
            $table->string('irrigation_type', 50)->nullable(); // drip, sprinkler, flood, etc.
            
            // Productivity Metrics (cached for performance)
            $table->decimal('total_yield_kg', 10, 2)->default(0); // lifetime yield
            $table->decimal('total_labour_cost', 12, 2)->default(0); // lifetime labour cost
            $table->decimal('total_input_cost', 12, 2)->default(0); // lifetime input cost
            $table->decimal('total_revenue', 12, 2)->default(0); // lifetime revenue
            $table->decimal('average_yield_per_cycle', 8, 2)->default(0); // kg per cycle
            $table->integer('total_crop_cycles')->default(0); // number of completed cycles
            
            // Operational Data
            $table->timestamp('last_planted_date')->nullable();
            $table->timestamp('last_harvest_date')->nullable();
            $table->uuid('current_crop_cycle_id')->nullable(); // active crop cycle
            $table->integer('days_since_last_harvest')->nullable();
            $table->boolean('needs_maintenance')->default(false);
            $table->text('maintenance_notes')->nullable();
            
            // Performance Scoring (0-100 scale)
            $table->decimal('yield_performance_score', 5, 2)->default(0); // compared to farm average
            $table->decimal('cost_efficiency_score', 5, 2)->default(0); // cost per kg compared to farm average
            $table->decimal('reliability_score', 5, 2)->default(100); // consistency of performance
            
            // Issues & Observations Summary (cached)
            $table->integer('total_observations')->default(0);
            $table->integer('critical_observations')->default(0);
            $table->timestamp('last_observation_date')->nullable();
            $table->json('common_issues')->nullable(); // frequently occurring issues
            
            // Archive & Management
            $table->boolean('is_active')->default(true)->index();
            $table->text('archive_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->index();
            $table->uuid('updated_by')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['farm_id', 'status']);
            $table->index(['farm_id', 'is_active']);
            $table->index(['farm_id', 'layout_order']);
            $table->index(['current_crop_cycle_id']);
            $table->index(['health_score']);
            $table->index(['yield_performance_score']);
            
            // Unique constraint for bed names within a farm
            $table->unique(['farm_id', 'name'], 'unique_bed_name_per_farm');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beds');
    }
};