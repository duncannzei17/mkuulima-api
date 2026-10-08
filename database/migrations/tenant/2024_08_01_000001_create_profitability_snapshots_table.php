<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profitability_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crop_cycle_id');
            $table->uuid('farm_id');
            
            // Cost components
            $table->decimal('total_expenses', 15, 2)->default(0);
            $table->decimal('labour_cost', 15, 2)->default(0);
            $table->decimal('input_cost', 15, 2)->default(0);
            $table->decimal('inventory_cost', 15, 2)->default(0);
            $table->decimal('transport_cost', 15, 2)->default(0);
            $table->decimal('processing_cost', 15, 2)->default(0);
            $table->decimal('storage_cost', 15, 2)->default(0);
            $table->decimal('miscellaneous_cost', 15, 2)->default(0);
            
            // Revenue components
            $table->decimal('total_revenue', 15, 2)->default(0);
            $table->decimal('gross_sales', 15, 2)->default(0);
            $table->decimal('deductions_amount', 15, 2)->default(0);
            $table->decimal('net_sales', 15, 2)->default(0);
            
            // Profitability metrics
            $table->decimal('cost_of_production', 15, 2)->default(0);
            $table->decimal('gross_profit', 15, 2)->default(0);
            $table->decimal('net_profit', 15, 2)->default(0);
            $table->decimal('profit_margin', 5, 2)->default(0);
            $table->decimal('roi_percentage', 5, 2)->default(0);
            
            // Yield metrics
            $table->decimal('expected_yield', 12, 3)->default(0);
            $table->decimal('actual_yield', 12, 3)->default(0);
            $table->decimal('yield_efficiency', 5, 2)->default(0);
            $table->string('yield_unit', 20)->default('kg');
            
            // Per-unit profitability
            $table->decimal('profit_per_kg', 15, 2)->default(0);
            $table->decimal('profit_per_bed', 15, 2)->default(0);
            $table->decimal('profit_per_acre', 15, 2)->default(0);
            $table->decimal('cost_per_kg', 15, 2)->default(0);
            $table->decimal('revenue_per_kg', 15, 2)->default(0);
            
            // Area metrics
            $table->decimal('total_area', 10, 2)->default(0);
            $table->string('area_unit', 20)->default('acres');
            $table->integer('number_of_beds')->default(0);
            
            // Cost distribution percentages
            $table->decimal('labour_cost_percentage', 5, 2)->default(0);
            $table->decimal('input_cost_percentage', 5, 2)->default(0);
            $table->decimal('inventory_cost_percentage', 5, 2)->default(0);
            $table->decimal('transport_cost_percentage', 5, 2)->default(0);
            $table->decimal('other_cost_percentage', 5, 2)->default(0);
            
            // Performance indicators
            $table->decimal('season_health_score', 5, 2)->default(0);
            $table->decimal('efficiency_score', 5, 2)->default(0);
            $table->decimal('cost_efficiency_score', 5, 2)->default(0);
            $table->decimal('yield_performance_score', 5, 2)->default(0);
            
            // Market performance
            $table->decimal('average_selling_price', 15, 2)->default(0);
            $table->decimal('market_price_variance', 5, 2)->default(0);
            $table->decimal('price_realization', 5, 2)->default(0);
            
            // Time tracking
            $table->integer('crop_duration_days')->default(0);
            $table->date('cycle_start_date')->nullable();
            $table->date('cycle_end_date')->nullable();
            $table->date('first_harvest_date')->nullable();
            $table->date('last_harvest_date')->nullable();
            
            // Status and metadata
            $table->enum('calculation_status', ['pending', 'calculating', 'completed', 'error'])->default('pending');
            $table->json('calculation_metadata')->nullable();
            $table->json('cost_breakdown')->nullable();
            $table->json('revenue_breakdown')->nullable();
            $table->json('performance_indicators')->nullable();
            $table->text('insights')->nullable();
            $table->text('recommendations')->nullable();
            
            // Timestamps
            $table->timestamp('calculated_at');
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('cascade');
            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['crop_cycle_id', 'calculated_at']);
            $table->index(['farm_id', 'calculated_at']);
            $table->index(['calculated_at']);
            $table->index(['season_health_score', 'calculated_at']);
            $table->index(['net_profit', 'calculated_at']);
            $table->index(['roi_percentage', 'calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profitability_snapshots');
    }
};