<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id'); // Owner
            $table->string('name');
            $table->json('location'); // County → Ward → Village structure
            $table->decimal('size', 10, 2); // Farm size in acres
            $table->string('size_unit')->default('acres'); // acres, hectares, etc.
            $table->enum('type', [
                'vegetables', 'mixed', 'dairy', 'poultry', 'crops', 'livestock', 
                'fruits', 'herbs', 'flowers', 'other'
            ]);
            $table->enum('ownership', ['owned', 'leased', 'shared', 'other']);
            $table->year('starting_year');
            $table->string('tenant_schema_name')->unique(); // For multi-tenancy
            $table->string('registration_number')->nullable();
            $table->string('tax_id')->nullable();
            $table->enum('subscription_tier', ['basic', 'pro', 'enterprise'])->default('basic');
            $table->enum('status', ['active', 'inactive', 'archived', 'suspended'])->default('active');
            $table->json('coordinates')->nullable(); // GPS coordinates
            $table->string('soil_type')->nullable();
            $table->string('climate_zone')->nullable();
            $table->string('water_source')->nullable();
            $table->boolean('organic_certified')->default(false);
            $table->text('description')->nullable();
            $table->json('settings')->nullable(); // Farm-specific settings
            $table->json('metadata')->nullable(); // Additional data
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Indexes
            $table->index(['user_id']);
            $table->index(['status']);
            $table->index(['type']);
            $table->index(['tenant_schema_name']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farms');
    }
};