<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('market_id');
            
            // Crop and pricing information
            $table->string('crop_name', 100);
            $table->string('crop_variety', 100)->nullable();
            $table->string('grade', 50)->default('mixed'); // A, B, C, mixed, premium
            $table->string('unit', 20)->default('kg'); // kg, piece, bunch, bag, etc.
            
            // Price details
            $table->decimal('min_price', 10, 2);
            $table->decimal('max_price', 10, 2);
            $table->decimal('average_price', 10, 2);
            $table->decimal('modal_price', 10, 2)->nullable(); // Most common price
            $table->decimal('wholesale_price', 10, 2)->nullable();
            $table->decimal('retail_price', 10, 2)->nullable();
            
            // Market conditions
            $table->date('price_date');
            $table->enum('demand_level', ['very_low', 'low', 'normal', 'high', 'very_high'])->default('normal');
            $table->enum('supply_level', ['very_low', 'low', 'normal', 'high', 'very_high'])->default('normal');
            $table->decimal('quantity_available', 10, 2)->nullable(); // kg/tonnes available
            $table->decimal('quantity_sold', 10, 2)->nullable(); // kg/tonnes sold
            
            // Quality and conditions
            $table->enum('quality_rating', ['poor', 'fair', 'good', 'excellent'])->default('good');
            $table->string('weather_conditions', 100)->nullable();
            $table->decimal('transport_cost_per_kg', 8, 4)->default(0);
            $table->boolean('is_peak_season')->default(false);
            $table->boolean('is_market_day')->default(true);
            
            // Data source and reliability
            $table->enum('data_source', ['farmer_report', 'market_survey', 'buyer_feedback', 'government_data', 'api_feed'])->default('farmer_report');
            $table->decimal('data_confidence', 3, 2)->default(0.5); // 0-1 confidence score
            $table->uuid('reported_by')->nullable();
            $table->text('notes')->nullable();
            
            // Price analytics
            $table->decimal('price_change_percentage', 5, 2)->default(0); // % change from previous period
            $table->decimal('seasonal_deviation', 5, 2)->default(0); // % deviation from seasonal average
            $table->integer('days_since_last_update')->default(0);
            $table->boolean('is_price_alert')->default(false); // Significant price change
            
            // Forecasting data
            $table->decimal('predicted_next_price', 10, 2)->nullable();
            $table->date('prediction_date')->nullable();
            $table->decimal('prediction_confidence', 3, 2)->nullable(); // 0-1 confidence
            $table->json('price_factors')->nullable(); // Factors affecting price
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('market_id')->references('id')->on('markets')->onDelete('cascade');
            $table->foreign('reported_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['market_id', 'price_date']);
            $table->index(['crop_name', 'price_date']);
            $table->index(['crop_name', 'grade', 'price_date']);
            $table->index(['price_date', 'data_source']);
            $table->index(['demand_level', 'supply_level']);
            $table->index(['is_price_alert', 'price_date']);
            $table->index('price_change_percentage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_prices');
    }
};