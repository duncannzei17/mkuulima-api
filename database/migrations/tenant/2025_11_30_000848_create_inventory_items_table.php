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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('unit');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('quantity', 10, 3)->default(0);
            $table->decimal('current_quantity', 10, 3)->default(0);
            $table->decimal('min_quantity', 10, 3)->default(0);
            $table->decimal('max_quantity', 10, 3)->nullable();
            $table->decimal('reorder_level', 10, 3)->nullable();
            $table->decimal('cost_per_unit', 10, 2)->default(0);
            $table->decimal('average_cost', 10, 2)->default(0);
            $table->decimal('total_value', 12, 2)->default(0);
            $table->decimal('total_quantity_in', 12, 3)->default(0);
            $table->decimal('total_quantity_out', 12, 3)->default(0);
            $table->string('supplier')->nullable();
            $table->string('supplier_contact')->nullable();
            $table->string('brand')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('storage_location')->nullable();
            $table->text('storage_conditions')->nullable();
            $table->enum('status', ['active', 'inactive', 'expired', 'discontinued'])->default('active');
            $table->boolean('track_expiry')->default(false);
            $table->boolean('track_batches')->default(false);
            $table->boolean('is_consumable')->default(true);
            $table->date('last_movement_date')->nullable();
            $table->date('last_stock_in_date')->nullable();
            $table->date('last_stock_out_date')->nullable();
            $table->integer('movement_count')->default(0);
            $table->json('tags')->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('attachments')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['farm_id', 'category']);
            $table->index(['farm_id', 'status']);
            $table->index('expiry_date');
            $table->index('sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
