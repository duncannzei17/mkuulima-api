<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sell_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Order basics
            $table->string('order_number', 20)->unique();
            $table->uuid('crop_cycle_id');
            $table->uuid('harvest_id')->nullable(); // Link to specific harvest
            $table->uuid('buyer_id')->nullable(); // Target buyer
            $table->uuid('market_id')->nullable(); // Target market
            $table->uuid('created_by');
            
            // Crop details
            $table->string('crop_name', 100);
            $table->string('crop_variety', 100)->nullable();
            $table->decimal('quantity', 10, 2); // kg
            $table->string('unit', 20)->default('kg');
            $table->string('grade', 50)->default('mixed'); // A, B, C, mixed
            $table->text('quality_description')->nullable();
            
            // Pricing
            $table->decimal('asking_price_per_unit', 10, 2);
            $table->decimal('minimum_acceptable_price', 10, 2);
            $table->decimal('agreed_price_per_unit', 10, 2)->nullable();
            $table->decimal('total_value', 12, 2)->nullable();
            $table->boolean('is_negotiable')->default(true);
            
            // Logistics and pickup
            $table->string('pickup_location', 255);
            $table->decimal('pickup_latitude', 10, 8)->nullable();
            $table->decimal('pickup_longitude', 11, 8)->nullable();
            $table->string('delivery_location', 255)->nullable();
            $table->decimal('delivery_latitude', 10, 8)->nullable();
            $table->decimal('delivery_longitude', 11, 8)->nullable();
            
            // Scheduling
            $table->datetime('preferred_pickup_time');
            $table->datetime('latest_pickup_time')->nullable();
            $table->datetime('expected_delivery_time')->nullable();
            $table->boolean('flexible_timing')->default(false);
            
            // Order status and workflow
            $table->enum('status', [
                'pending',           // Order created, awaiting action
                'buyer_confirmed',   // Buyer confirmed interest
                'price_agreed',      // Price negotiated and agreed
                'logistics_assigned', // Rider/transport assigned
                'picked_up',         // Produce collected from farm
                'in_transit',        // Being transported
                'delivered',         // Delivered to buyer/market
                'payment_received',  // Payment completed
                'completed',         // Order fully completed
                'cancelled',         // Order cancelled
                'expired'           // Order expired
            ])->default('pending');
            
            // Logistics integration (Siku Mpya)
            $table->string('logistics_provider', 50)->default('siku_mpya');
            $table->string('logistics_order_id', 100)->nullable();
            $table->string('rider_id', 100)->nullable();
            $table->string('rider_name')->nullable();
            $table->string('rider_phone', 20)->nullable();
            $table->string('vehicle_details')->nullable();
            $table->decimal('transport_cost', 10, 2)->default(0);
            $table->decimal('transport_distance', 8, 2)->nullable(); // km
            
            // Payment and settlement
            $table->string('payment_method', 50)->nullable(); // cash, mpesa, bank, etc.
            $table->string('payment_reference', 100)->nullable();
            $table->decimal('payment_received', 12, 2)->default(0);
            $table->datetime('payment_received_at')->nullable();
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->nullable();
            
            // Order tracking and analytics
            $table->text('special_instructions')->nullable();
            $table->json('status_history')->nullable(); // Track status changes
            $table->datetime('order_fulfilled_at')->nullable();
            $table->integer('fulfillment_time_hours')->nullable(); // Hours from order to completion
            $table->decimal('customer_rating', 3, 2)->nullable(); // 1-5 stars
            $table->text('customer_feedback')->nullable();
            
            // Cancellation and issues
            $table->string('cancellation_reason')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->datetime('cancelled_at')->nullable();
            $table->text('cancellation_notes')->nullable();
            $table->boolean('has_issues')->default(false);
            $table->text('issue_description')->nullable();
            
            // Business intelligence
            $table->decimal('market_price_at_order', 10, 2)->nullable(); // Market price when order created
            $table->decimal('price_premium_percentage', 5, 2)->default(0); // % above/below market
            $table->boolean('is_repeat_customer')->default(false);
            $table->integer('buyer_order_count')->default(0);
            $table->enum('urgency_level', ['low', 'normal', 'high', 'urgent'])->default('normal');
            
            $table->timestamps();
            
            // Foreign keys
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('cascade');
            $table->foreign('buyer_id')->references('id')->on('buyers')->onDelete('set null');
            $table->foreign('market_id')->references('id')->on('markets')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('cancelled_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['status', 'created_at']);
            $table->index(['crop_name', 'status']);
            $table->index(['buyer_id', 'status']);
            $table->index(['created_by', 'created_at']);
            $table->index(['preferred_pickup_time', 'status']);
            $table->index(['logistics_order_id', 'logistics_provider']);
            $table->index(['order_number']);
            $table->index(['market_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sell_orders');
    }
};