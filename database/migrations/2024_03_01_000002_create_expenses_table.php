<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('expense_date');
            $table->time('expense_time')->default('12:00:00');
            $table->decimal('amount', 10, 2);
            $table->string('description', 500);
            $table->uuid('category_id');
            $table->string('subcategory', 100)->nullable();
            $table->uuid('crop_cycle_id')->nullable();
            $table->uuid('bed_id')->nullable();
            $table->uuid('task_id')->nullable();
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->string('paid_to', 200)->nullable();
            $table->enum('status', ['approved', 'pending', 'rejected', 'draft'])->default('approved');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->enum('payment_method', ['cash', 'm_pesa', 'bank_transfer', 'cheque', 'credit', 'mobile_money', 'other'])->default('cash');
            $table->string('payment_reference', 100)->nullable();
            $table->string('payment_details', 200)->nullable();
            $table->json('receipt_photos')->nullable();
            $table->string('receipt_number', 100)->nullable();
            $table->string('supplier_name', 200)->nullable();
            $table->string('supplier_phone', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->decimal('quantity', 10, 2)->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('transport_cost', 10, 2)->default(0);
            $table->decimal('handling_fee', 10, 2)->default(0);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_name', 100)->nullable();
            $table->boolean('is_recurring')->default(false);
            $table->string('recurrence_pattern', 100)->nullable();
            $table->uuid('parent_expense_id')->nullable();
            $table->boolean('is_planned')->default(false);
            $table->uuid('budget_item_id')->nullable();
            $table->decimal('variance_from_budget', 10, 2)->nullable();
            $table->json('edit_history')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->uuid('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('expense_categories')->restrictOnDelete();
            $table->index('expense_date');
            $table->index('category_id');
            $table->index('created_by');
            $table->index('approved_by');
            $table->index('status');
            $table->index('crop_cycle_id');
            $table->index('payment_method');
            $table->index('is_recurring');
            $table->index('is_deleted');
            $table->index(['expense_date', 'status']);
            $table->index(['category_id', 'status']);
            $table->index(['created_by', 'status']);
            $table->index(['crop_cycle_id', 'status']);
            $table->index(['is_deleted', 'status']);
        });

        if (Schema::hasTable('crop_cycles')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->nullOnDelete();
            });
        }

        if (Schema::hasTable('crop_tasks')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreign('task_id')->references('id')->on('crop_tasks')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
