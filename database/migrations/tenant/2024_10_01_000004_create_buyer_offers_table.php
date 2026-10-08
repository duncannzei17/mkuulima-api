<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_offers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Offer basics
            $table->string('offer_number', 20)->unique();
            $table->uuid('buyer_id');
            $table->uuid('farm_id')->nullable(); // Target specific farm
            $table->string('crop_name', 100);
            $table->string('crop_variety', 100)->nullable();
            
            // Offer details
            $table->decimal('quantity_needed', 10, 2); // kg
            $table->string('unit', 20)->default('kg');
            $table->decimal('offered_price_per_unit', 10, 2);
            $table->decimal('total_offer_value', 12, 2);
            $table->string('preferred_grade', 50)->nullable(); // A, B, C, mixed
            $table->text('quality_requirements')->nullable();
            
            // Timing and delivery
            $table->date('needed_by_date');
            $table->date('offer_valid_until');
            $table->boolean('flexible_date')->default(false);
            $table->string('delivery_terms', 100)->default('pickup'); // pickup, delivery, market
            $table->string('preferred_location', 255)->nullable();
            $table->decimal('location_latitude', 10, 8)->nullable();
            $table->decimal('location_longitude', 11, 8)->nullable();
            
            // Payment terms
            $table->string('payment_method', 50)->default('cash'); // cash, mpesa, bank, check
            $table->enum('payment_timing', ['on_delivery', 'within_24h', 'within_week', 'net_30'])->default('on_delivery');
            $table->decimal('advance_payment_percentage', 5, 2)->default(0); // % paid upfront
            $table->boolean('price_negotiable')->default(true);
            
            // Offer status and workflow
            $table->enum('status', [
                'active',           // Offer is active and accepting responses
                'farmer_interested', // Farmer expressed interest
                'negotiating',      // Price/terms being negotiated
                'accepted',         // Offer accepted, becoming sell order
                'partially_filled', // Some quantity fulfilled
                'fulfilled',        // Completely fulfilled
                'expired',          // Offer expired
                'cancelled',        // Buyer cancelled
                'rejected'          // Farmer rejected
            ])->default('active');
            
            // Response and matching
            $table->integer('farmer_responses')->default(0);
            $table->decimal('quantity_committed', 10, 2)->default(0); // How much farmers committed
            $table->decimal('quantity_received', 10, 2)->default(0); // How much actually received
            $table->json('interested_farmers')->nullable(); // Array of farmer IDs who responded
            $table->uuid('accepted_by')->nullable(); // Which farmer's offer was accepted
            $table->datetime('accepted_at')->nullable();
            
            // Market intelligence
            $table->decimal('market_price_at_offer', 10, 2)->nullable(); // Current market price
            $table->decimal('price_premium_percentage', 5, 2)->default(0); // % above/below market
            $table->enum('urgency_level', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->boolean('is_bulk_order')->default(false); // Large quantity order
            $table->boolean('is_recurring_offer')->default(false); // Regular weekly/monthly offer
            
            // Communication and notes
            $table->text('special_requirements')->nullable();
            $table->text('buyer_notes')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email')->nullable();
            
            // Analytics and tracking
            $table->datetime('offer_fulfilled_at')->nullable();
            $table->integer('fulfillment_time_hours')->nullable();
            $table->decimal('farmer_rating', 3, 2)->nullable(); // Buyer rates farmer 1-5
            $table->text('farmer_feedback')->nullable();
            $table->boolean('will_reorder')->default(false);
            
            // Auto-matching and AI
            $table->json('matching_criteria')->nullable(); // AI matching parameters
            $table->decimal('match_score', 3, 2)->nullable(); // 0-1 AI matching score
            $table->boolean('auto_match_enabled')->default(false);
            $table->json('suggested_farmers')->nullable(); // AI-suggested farmer matches
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('buyer_id')->references('id')->on('buyers')->onDelete('cascade');
            $table->foreign('accepted_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['status', 'created_at']);
            $table->index(['crop_name', 'status']);
            $table->index(['buyer_id', 'status']);
            $table->index(['needed_by_date', 'status']);
            $table->index(['offer_valid_until', 'status']);
            $table->index(['offer_number']);
            $table->index(['is_bulk_order', 'urgency_level']);
            $table->index(['price_premium_percentage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_offers');
    }
};