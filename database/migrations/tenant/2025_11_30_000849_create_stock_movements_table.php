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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('inventory_item_id');
            $table->enum('movement_type', ['in', 'out', 'adjustment', 'expired_removal'])->default('out');
            $table->decimal('quantity', 10, 3);
            $table->decimal('cost_per_unit', 10, 2)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->decimal('balance_after', 10, 3)->nullable();
            $table->date('movement_date');
            $table->string('batch_number')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('crop_cycle_id')->nullable();
            $table->uuid('bed_id')->nullable();
            $table->uuid('labour_task_id')->nullable();
            $table->uuid('worker_id')->nullable();
            $table->uuid('expense_id')->nullable();
            $table->enum('status', ['pending', 'confirmed', 'cancelled'])->default('confirmed');
            $table->json('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'movement_type']);
            $table->index(['inventory_item_id', 'movement_date']);
            $table->index(['movement_date', 'movement_type']);
            $table->index('crop_cycle_id');
            $table->index('worker_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
