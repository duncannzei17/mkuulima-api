<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bed_analytics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bed_id')->index();
            $table->uuid('farm_id')->index();
            $table->date('analysis_date')->index();
            $table->enum('analysis_period', ['daily', 'weekly', 'monthly', 'seasonal', 'annual'])->default('monthly')->index();
            
            // Performance Scores (0-100 scale)
            $table->decimal('overall_performance_score', 5, 2)->default(0);
            $table->decimal('yield_performance_score', 5, 2)->default(0);
            $table->decimal('cost_efficiency_score', 5, 2)->default(0);
            $table->decimal('timeline_performance_score', 5, 2)->default(0);
            $table->decimal('quality_performance_score', 5, 2)->default(0);
            
            // Yield Analytics
            $table->decimal('total_yield_kg', 10, 2)->default(0);
            $table->decimal('average_yield_per_cycle', 8, 2)->default(0);
            $table->decimal('yield_per_sqm', 8, 2)->default(0);
            $table->decimal('yield_trend_percentage', 6, 2)->default(0); // growth/decline trend
            $table->integer('successful_harvests')->default(0);
            $table->integer('failed_harvests')->default(0);
            
            // Cost Analytics
            $table->decimal('total_costs', 12, 2)->default(0);
            $table->decimal('labour_costs', 10, 2)->default(0);
            $table->decimal('input_costs', 10, 2)->default(0);
            $table->decimal('harvest_costs', 10, 2)->default(0);
            $table->decimal('maintenance_costs', 10, 2)->default(0);
            $table->decimal('cost_per_kg', 8, 2)->default(0);
            $table->decimal('cost_per_sqm', 8, 2)->default(0);
            $table->decimal('cost_trend_percentage', 6, 2)->default(0);
            
            // Revenue & Profitability
            $table->decimal('total_revenue', 12, 2)->default(0);
            $table->decimal('gross_profit', 12, 2)->default(0);
            $table->decimal('net_profit', 12, 2)->default(0);
            $table->decimal('profit_margin_percentage', 6, 2)->default(0);
            $table->decimal('roi_percentage', 8, 2)->default(0);
            $table->decimal('revenue_per_sqm', 8, 2)->default(0);
            
            // Resource Utilization
            $table->decimal('utilization_rate_percentage', 5, 2)->default(0); // % of time bed was productive
            $table->integer('idle_days')->default(0); // days between crops
            $table->integer('productive_days')->default(0); // days with active crops
            $table->decimal('crop_rotation_efficiency', 5, 2)->default(0); // rotation planning efficiency
            
            // Health & Maintenance
            $table->decimal('average_health_score', 5, 2)->default(100);
            $table->decimal('health_trend_percentage', 6, 2)->default(0);
            $table->integer('total_observations', false, true)->default(0);
            $table->integer('critical_issues', false, true)->default(0);
            $table->integer('maintenance_interventions')->default(0);
            $table->decimal('downtime_days', 8, 2)->default(0); // days out of production for maintenance
            
            // Quality Metrics
            $table->decimal('average_harvest_quality_score', 5, 2)->default(0); // 0-100
            $table->decimal('marketable_yield_percentage', 5, 2)->default(100);
            $table->decimal('waste_percentage', 5, 2)->default(0);
            $table->decimal('quality_consistency_score', 5, 2)->default(0); // variance in quality
            
            // Environmental Impact
            $table->decimal('water_usage_liters', 12, 2)->default(0);
            $table->decimal('water_efficiency_score', 5, 2)->default(0); // water used per kg yield
            $table->decimal('soil_health_impact', 6, 2)->default(0); // positive/negative soil impact
            $table->integer('sustainable_practices_count')->default(0); // count of sustainable methods used
            
            // Comparative Analysis
            $table->decimal('farm_average_comparison', 6, 2)->default(0); // % better/worse than farm average
            $table->integer('farm_ranking')->nullable(); // ranking among all beds on farm
            $table->decimal('peer_comparison_score', 5, 2)->default(0); // compared to similar beds
            $table->enum('performance_category', ['top_performer', 'above_average', 'average', 'below_average', 'poor_performer'])->nullable();
            
            // Trend Analysis
            $table->json('yield_trend_data')->nullable(); // historical yield data points
            $table->json('cost_trend_data')->nullable(); // historical cost data points
            $table->json('health_trend_data')->nullable(); // historical health data points
            $table->json('seasonal_patterns')->nullable(); // seasonal performance patterns
            
            // Forecasting
            $table->decimal('predicted_next_yield', 8, 2)->nullable();
            $table->decimal('predicted_next_cost', 10, 2)->nullable();
            $table->decimal('predicted_profitability', 10, 2)->nullable();
            $table->integer('confidence_level')->nullable(); // prediction confidence 0-100
            
            // Risk Assessment
            $table->enum('risk_level', ['very_low', 'low', 'medium', 'high', 'very_high'])->default('low')->index();
            $table->json('risk_factors')->nullable(); // identified risk factors
            $table->decimal('failure_probability', 5, 2)->default(0); // % probability of crop failure
            $table->text('recommendations')->nullable(); // AI-generated improvement recommendations
            
            // Seasonal Context
            $table->enum('season', ['dry', 'wet', 'transition'])->nullable();
            $table->json('weather_impact_analysis')->nullable();
            $table->decimal('weather_adaptation_score', 5, 2)->default(0);
            
            // Calculation Metadata
            $table->timestamp('calculated_at')->nullable();
            $table->uuid('calculated_by')->nullable();
            $table->json('calculation_parameters')->nullable(); // parameters used for analytics
            $table->boolean('is_validated')->default(false); // if analytics have been validated
            $table->timestamp('validated_at')->nullable();
            $table->uuid('validated_by')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['bed_id', 'analysis_date']);
            $table->index(['bed_id', 'analysis_period']);
            $table->index(['farm_id', 'analysis_date']);
            $table->index(['farm_id', 'overall_performance_score']);
            $table->index(['farm_id', 'performance_category']);
            $table->index(['farm_id', 'risk_level']);
            $table->index(['calculated_at']);
            $table->index(['season']);
            
            // Unique constraint for bed analytics per period
            $table->unique(['bed_id', 'analysis_date', 'analysis_period'], 'unique_bed_analytics_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_analytics');
    }
};