<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crop_cycle_id')->nullable();
            $table->uuid('bed_id')->nullable();
            $table->uuid('worker_id')->nullable();
            $table->uuid('created_by');
            
            // Observation details
            $table->date('observation_date');
            $table->enum('observation_type', [
                'pest',
                'disease',
                'weather_impact',
                'nutrient_deficiency',
                'water_stress',
                'physical_damage',
                'growth_issues',
                'labour_related',
                'market_issue',
                'soil_condition',
                'equipment_issue',
                'other'
            ]);
            $table->enum('severity', ['low', 'medium', 'high'])->default('medium');
            $table->text('description');
            $table->text('location_notes')->nullable();
            
            // Media and documentation
            $table->json('photo_urls')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('weather_conditions')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->decimal('humidity', 5, 2)->nullable();
            
            // Workflow and approval
            $table->enum('status', ['pending', 'approved', 'rejected', 'resolved'])->default('approved');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // Impact and tracking
            $table->boolean('is_critical')->default(false);
            $table->boolean('requires_immediate_action')->default(false);
            $table->decimal('estimated_impact_percentage', 5, 2)->nullable();
            $table->decimal('affected_area', 10, 2)->nullable();
            $table->string('affected_area_unit', 20)->default('sqm');
            
            // Resolution and follow-up
            $table->boolean('is_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->json('follow_up_tasks')->nullable();
            
            // Analytics and insights
            $table->integer('season_health_impact')->default(0);
            $table->decimal('yield_impact_estimate', 5, 2)->default(0);
            $table->decimal('cost_impact_estimate', 15, 2)->default(0);
            $table->json('ai_insights')->nullable();
            $table->json('recommendations')->nullable();
            
            // Correlation tracking
            $table->uuid('related_task_id')->nullable();
            $table->uuid('related_expense_id')->nullable();
            $table->string('growth_stage')->nullable();
            $table->integer('days_after_planting')->nullable();
            
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('cascade');
            // Workers are represented by users in the tenant schema
            $table->foreign('worker_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['observation_date', 'severity']);
            $table->index(['crop_cycle_id', 'observation_date']);
            $table->index(['observation_type', 'severity']);
            $table->index(['status', 'created_at']);
            $table->index(['is_critical', 'observation_date']);
            $table->index(['worker_id', 'observation_date']);
            $table->index(['bed_id', 'observation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_observations');
    }
};
