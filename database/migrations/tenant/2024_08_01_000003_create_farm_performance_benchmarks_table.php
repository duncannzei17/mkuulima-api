<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_performance_benchmarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            
            // Benchmark period
            $table->string('benchmark_period'); // e.g., 'Q1_2024', 'SEASON_2024_A'
            $table->date('period_start');
            $table->date('period_end');
            $table->string('crop_type')->nullable();
            
            // Performance metrics
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('total_costs', 15, 2)->default(0);
            $table->decimal('net_profit', 15, 2)->default(0);
            $table->decimal('profit_margin', 5, 2)->default(0);
            $table->decimal('roi_percentage', 5, 2)->default(0);
            
            // Efficiency metrics
            $table->decimal('average_yield_efficiency', 5, 2)->default(0);
            $table->decimal('cost_per_kg', 15, 2)->default(0);
            $table->decimal('revenue_per_kg', 15, 2)->default(0);
            $table->decimal('labour_efficiency', 5, 2)->default(0);
            
            // Resource utilization
            $table->decimal('land_utilization', 5, 2)->default(0);
            $table->decimal('input_efficiency', 5, 2)->default(0);
            $table->integer('crop_cycles_completed')->default(0);
            $table->decimal('average_cycle_duration', 8, 2)->default(0);
            
            // Cost distribution
            $table->decimal('labour_cost_ratio', 5, 2)->default(0);
            $table->decimal('input_cost_ratio', 5, 2)->default(0);
            $table->decimal('transport_cost_ratio', 5, 2)->default(0);
            $table->decimal('overhead_cost_ratio', 5, 2)->default(0);
            
            // Market performance
            $table->decimal('price_realization', 5, 2)->default(0);
            $table->decimal('sales_efficiency', 5, 2)->default(0);
            $table->integer('buyer_relationships_count')->default(0);
            
            // Quality indicators
            $table->decimal('overall_score', 5, 2)->default(0);
            $table->decimal('sustainability_score', 5, 2)->default(0);
            $table->decimal('innovation_score', 5, 2)->default(0);
            
            // Comparative metrics
            $table->decimal('industry_percentile', 5, 2)->nullable();
            $table->decimal('regional_percentile', 5, 2)->nullable();
            $table->boolean('above_industry_average')->default(false);
            
            // Improvement tracking
            $table->decimal('period_over_period_growth', 5, 2)->default(0);
            $table->json('improvement_areas')->nullable();
            $table->json('strength_areas')->nullable();
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            
            // Indexes
            $table->index(['farm_id', 'benchmark_period']);
            $table->index(['period_start', 'period_end']);
            $table->index(['crop_type', 'overall_score']);
            $table->index(['overall_score', 'created_at']);
            
            // Ensure one benchmark per period per farm
            $table->unique(['farm_id', 'benchmark_period', 'crop_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_performance_benchmarks');
    }
};