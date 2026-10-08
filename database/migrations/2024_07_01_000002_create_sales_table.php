<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crop_cycle_id')->nullable();
            $table->uuid('harvest_id')->nullable();
            $table->date('sale_date');
            $table->decimal('quantity_sold', 12, 3);
            $table->string('unit');
            $table->decimal('price_per_unit', 10, 2);
            $table->decimal('gross_income', 12, 2);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_income', 12, 2);
            $table->uuid('buyer_id')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('market_location')->nullable();
            $table->enum('sale_type', ['farmgate', 'market', 'door_to_door', 'pickup', 'delivery', 'online', 'bulk_order', 'spot_sale'])->default('market');
            $table->enum('payment_method', ['cash', 'm_pesa', 'bank_transfer', 'cheque', 'credit', 'mobile_money', 'crypto', 'other'])->default('cash');
            $table->enum('payment_status', ['paid', 'pending', 'partial', 'overdue'])->default('paid');
            $table->date('payment_due_date')->nullable();
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_outstanding', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('delivery_info')->nullable();
            $table->decimal('transport_cost', 10, 2)->default(0);
            $table->boolean('is_recurring_buyer')->default(false);
            $table->json('quality_feedback')->nullable();
            $table->timestamps();

            $table->index(['sale_date', 'status']);
            $table->index(['crop_cycle_id', 'sale_date']);
            $table->index(['buyer_id', 'sale_date']);
            $table->index(['payment_status', 'payment_due_date']);
            $table->index('status');
        });

        if (Schema::hasTable('buyers')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->foreign('buyer_id')->references('id')->on('buyers')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
