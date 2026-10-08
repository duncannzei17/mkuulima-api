<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profitability_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('crop_cycle_id')->nullable();
            
            // Insight metadata
            $table->enum('insight_type', [
                'cost_alert',
                'yield_performance',
                'profit_warning', 
                'efficiency_tip',
                'market_opportunity',
                'seasonal_comparison',
                'resource_optimization',
                'price_variance',
                'performance_benchmark'
            ]);
            
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('category', [
                'cost_management',
                'yield_optimization',
                'revenue_enhancement',
                'efficiency_improvement',
                'risk_mitigation',
                'market_intelligence'
            ]);
            
            // Insight content
            $table->string('title');
            $table->text('message');
            $table->text('recommendation')->nullable();
            $table->json('data_points')->nullable();
            
            // Impact analysis
            $table->decimal('potential_impact_amount', 15, 2)->nullable();
            $table->enum('impact_type', ['cost_saving', 'revenue_increase', 'efficiency_gain'])->nullable();
            $table->decimal('confidence_score', 5, 2)->default(0);
            
            // Actionability
            $table->boolean('is_actionable')->default(true);
            $table->json('suggested_actions')->nullable();
            $table->date('action_deadline')->nullable();
            
            // Status tracking
            $table->enum('status', ['new', 'viewed', 'acknowledged', 'acted_upon', 'dismissed'])->default('new');
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('acted_upon_at')->nullable();
            $table->text('action_notes')->nullable();
            
            // Context
            $table->string('crop_type')->nullable();
            $table->string('season')->nullable();
            $table->json('comparison_data')->nullable();
            $table->json('trend_data')->nullable();
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('cascade');
            
            // Indexes
            $table->index(['farm_id', 'status', 'priority']);
            $table->index(['crop_cycle_id', 'insight_type']);
            $table->index(['priority', 'status', 'created_at']);
            $table->index(['insight_type', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profitability_insights');
    }
};