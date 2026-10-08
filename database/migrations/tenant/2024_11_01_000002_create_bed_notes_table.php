<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure clean slate if a previous run partially created the table
        Schema::dropIfExists('bed_notes');

        Schema::create('bed_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bed_id')->index();
            $table->uuid('farm_id')->index();
            $table->date('note_date')->index();
            
            // Note Content
            $table->text('note_content');
            $table->enum('note_type', [
                'general', 'soil_issue', 'drainage_problem', 'pest_observation', 
                'disease_observation', 'nutrient_deficiency', 'water_stress',
                'maintenance_required', 'improvement_suggestion', 'weather_damage',
                'equipment_issue', 'harvest_quality', 'yield_observation'
            ])->default('general')->index();
            
            // Severity & Priority
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low')->index();
            $table->boolean('requires_action')->default(false)->index();
            $table->date('action_required_by')->nullable();
            $table->boolean('is_resolved')->default(false)->index();
            $table->date('resolved_date')->nullable();
            $table->text('resolution_notes')->nullable();
            
            // Impact Assessment
            $table->decimal('estimated_cost_impact', 10, 2)->default(0); // potential cost if not addressed
            $table->decimal('estimated_yield_impact', 8, 2)->default(0); // potential yield loss (kg)
            $table->integer('affected_area_percentage')->default(100); // % of bed affected (1-100)
            
            // Location & Context
            $table->string('specific_location', 100)->nullable(); // "north corner", "center", etc.
            $table->uuid('crop_cycle_id')->nullable(); // if related to specific crop
            $table->integer('days_after_planting')->nullable(); // crop growth context
            $table->enum('growth_stage', [
                'seedling', 'vegetative', 'flowering', 'fruiting', 'maturity', 'harvest'
            ])->nullable();
            
            // Environmental Context
            $table->json('weather_conditions')->nullable(); // weather during observation
            $table->decimal('temperature', 5, 2)->nullable(); // temperature when noted
            $table->decimal('humidity', 5, 2)->nullable(); // humidity when noted
            $table->boolean('after_rain')->default(false);
            $table->boolean('during_irrigation')->default(false);
            
            // Media & Documentation
            $table->json('photos')->nullable(); // array of photo URLs
            $table->decimal('gps_latitude', 10, 8)->nullable();
            $table->decimal('gps_longitude', 11, 8)->nullable();
            
            // Follow-up & Tracking
            $table->uuid('follow_up_task_id')->nullable(); // if task was created
            $table->timestamp('follow_up_date')->nullable();
            $table->boolean('recurring_issue')->default(false); // if this issue occurs regularly
            $table->integer('recurrence_count')->default(1); // how many times this type of issue has occurred
            
            // Workflow & Approval
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('submitted')->index();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // User Tracking
            $table->uuid('created_by')->index();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['bed_id', 'note_date']);
            $table->index(['bed_id', 'note_type']);
            $table->index(['bed_id', 'severity']);
            $table->index(['farm_id', 'note_date']);
            $table->index(['farm_id', 'requires_action']);
            $table->index(['farm_id', 'is_resolved']);
            $table->index(['crop_cycle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_notes');
    }
};
