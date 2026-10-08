<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Insight classification
            $table->enum('insight_type', [
                'price_alert',          // Significant price changes
                'demand_forecast',      // Demand predictions
                'supply_shortage',      // Low supply warnings
                'supply_oversupply',    // Oversupply warnings
                'weather_impact',       // Weather affecting prices
                'seasonal_trend',       // Seasonal price patterns
                'market_opportunity',   // Good selling opportunities
                'buyer_behavior',       // Buyer pattern insights
                'crop_recommendation',  // What to plant next
                'harvest_timing',       // Best harvest timing
                'transport_cost',       // Transport cost changes
                'quality_alert'         // Quality issues in market
            ]);
            
            // Content and targeting
            $table->string('title', 255);
            $table->text('description');
            $table->text('recommendation')->nullable();
            $table->string('crop_name', 100)->nullable(); // Crop-specific insights
            $table->uuid('market_id')->nullable(); // Market-specific insights
            $table->string('location_scope', 100)->nullable(); // county, region, national
            
            // Severity and urgency
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            $table->enum('urgency', ['low', 'medium', 'high', 'immediate'])->default('medium');
            $table->boolean('is_actionable')->default(true);
            $table->date('action_deadline')->nullable(); // When action needed by
            
            // Data and analytics
            $table->decimal('price_change_percentage', 5, 2)->nullable();
            $table->decimal('current_price', 10, 2)->nullable();
            $table->decimal('predicted_price', 10, 2)->nullable();
            $table->decimal('confidence_score', 3, 2)->default(0.5); // 0-1 AI confidence
            $table->json('supporting_data')->nullable(); // Data that supports this insight
            $table->json('affected_metrics')->nullable(); // Which metrics are affected
            
            // Timing and validity
            $table->date('insight_date');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_time_sensitive')->default(false);
            $table->integer('days_ahead_forecast')->nullable(); // How many days ahead
            
            // Source and generation
            $table->enum('data_source', [
                'price_analysis',       // Generated from price data
                'sales_pattern',        // From sales module data
                'weather_api',          // Weather service data
                'market_survey',        // Market research
                'buyer_feedback',       // Buyer reports
                'farmer_reports',       // Farmer observations
                'government_data',      // Official statistics
                'ai_analysis',          // AI/ML generated
                'manual_entry'          // Manually entered
            ])->default('ai_analysis');
            
            $table->uuid('generated_by')->nullable(); // User who created/triggered insight
            $table->string('algorithm_version', 20)->nullable(); // AI model version
            $table->json('generation_parameters')->nullable(); // Parameters used for generation
            
            // Impact and tracking
            $table->enum('potential_impact', ['low', 'medium', 'high', 'very_high'])->default('medium');
            $table->decimal('estimated_financial_impact', 12, 2)->nullable(); // Potential loss/gain
            $table->integer('farmers_affected')->nullable(); // How many farmers affected
            $table->decimal('market_share_affected', 5, 2)->nullable(); // % of market affected
            
            // User engagement
            $table->integer('views_count')->default(0);
            $table->integer('shares_count')->default(0);
            $table->integer('farmers_acted')->default(0); // How many took action
            $table->decimal('average_rating', 3, 2)->nullable(); // User rating 1-5
            $table->integer('ratings_count')->default(0);
            
            // Status and lifecycle
            $table->enum('status', [
                'draft',               // Being prepared
                'published',           // Live and visible
                'expired',             // Past validity date
                'superseded',          // Replaced by newer insight
                'archived'             // Archived for historical reference
            ])->default('published');
            
            $table->boolean('is_featured')->default(false); // Show prominently
            $table->boolean('send_notification')->default(false); // Trigger notifications
            $table->datetime('published_at')->nullable();
            $table->uuid('superseded_by')->nullable(); // ID of newer insight
            
            // Feedback and validation
            $table->text('farmer_feedback')->nullable();
            $table->boolean('was_accurate')->nullable(); // Post-event validation
            $table->decimal('accuracy_score', 3, 2)->nullable(); // How accurate prediction was
            $table->text('accuracy_notes')->nullable();
            $table->datetime('validated_at')->nullable();
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('market_id')->references('id')->on('markets')->onDelete('set null');
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('set null');
            // Self-referencing FK removed to avoid circular constraint issues in Postgres
            $table->index('superseded_by');
            
            // Indexes for performance
            $table->index(['insight_type', 'status']);
            $table->index(['crop_name', 'insight_date']);
            $table->index(['severity', 'urgency']);
            $table->index(['insight_date', 'valid_until']);
            $table->index(['is_featured', 'published_at']);
            $table->index(['location_scope', 'crop_name']);
            $table->index(['confidence_score', 'severity']);
            $table->index(['market_id', 'insight_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_insights');
    }
};
