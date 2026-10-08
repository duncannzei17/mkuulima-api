<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the tenant migration.
     */
    public function up(): void
    {
        Schema::create('inventory_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('inventory_item_id')->nullable();
            $table->enum('alert_type', ['low_stock', 'expiring', 'expired', 'reorder', 'batch_recall'])->default('low_stock');
            $table->string('title', 200);
            $table->text('message');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['active', 'acknowledged', 'resolved', 'dismissed'])->default('active');
            $table->date('alert_date');
            $table->json('alert_data')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->boolean('action_required')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->onDelete('cascade');
            $table->foreign('acknowledged_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            
            // Indexes for performance
            $table->index(['farm_id', 'status']);
            $table->index(['alert_type', 'severity']);
            $table->index(['alert_date', 'status']);
            $table->index('inventory_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');
    }
};
