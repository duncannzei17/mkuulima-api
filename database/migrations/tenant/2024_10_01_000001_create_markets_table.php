<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('markets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Basic market information
            $table->string('name');
            $table->string('market_type', 50)->default('local'); // local, wholesale, retail, export
            $table->text('description')->nullable();
            
            // Location details
            $table->string('county', 100);
            $table->string('ward', 100)->nullable();
            $table->string('location', 255);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('distance_from_farm', 8, 2)->nullable(); // km
            
            // Market characteristics
            $table->json('common_crops'); // Array of crops commonly sold here
            $table->json('operating_days'); // Array of operating days (Mon, Tue, etc.)
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->decimal('average_daily_volume', 12, 2)->default(0); // kg per day
            $table->integer('vendor_count')->default(0);
            
            // Contact and facilities
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_person')->nullable();
            $table->boolean('has_cold_storage')->default(false);
            $table->boolean('has_processing_facility')->default(false);
            $table->boolean('has_transport_access')->default(true);
            $table->string('payment_methods')->nullable(); // cash, mobile, bank
            
            // Market intelligence
            $table->decimal('average_markup_percentage', 5, 2)->default(0); // %
            $table->string('dominant_buyer_type', 50)->nullable(); // retailer, wholesaler, processor
            $table->enum('price_volatility', ['low', 'medium', 'high'])->default('medium');
            $table->decimal('market_rating', 3, 2)->default(0); // 0-5 stars
            $table->integer('total_reviews')->default(0);
            
            // Status and metadata
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->string('verification_status', 50)->default('pending');
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['county', 'ward']);
            $table->index(['is_active', 'is_verified']);
            $table->index(['market_type', 'is_active']);
            $table->index('distance_from_farm');
            $table->index(['name', 'location']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('markets');
    }
};