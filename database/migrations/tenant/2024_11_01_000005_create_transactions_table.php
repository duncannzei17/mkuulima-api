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
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Basic Transaction Details
            $table->string('transaction_reference')->unique()->index();
            $table->enum('type', ['income', 'expense'])->index();
            $table->enum('category', [
                'sales', 'labour_payment', 'supplier_payment', 'inventory_purchase',
                'equipment_purchase', 'fuel_transport', 'utilities', 'rent_fees',
                'insurance', 'loan_payment', 'tax_payment', 'miscellaneous',
                'refund', 'investment', 'grant_funding', 'loan_received'
            ])->index();
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['cash', 'mpesa', 'bank_transfer', 'cheque', 'mobile_money'])->index();
            $table->text('description')->nullable();
            $table->timestamp('transaction_date');
            
            // Linking to Other Modules
            $table->uuid('linked_id')->nullable()->index(); // Links to expense, labour, sale, inventory records
            $table->enum('linked_type', ['expense', 'labour', 'sale', 'inventory', 'supplier_payment'])->nullable()->index();
            
            // Status and Workflow
            $table->enum('status', [
                'draft', 'pending_approval', 'approved', 'processing', 'completed', 
                'failed', 'cancelled', 'partially_paid', 'refunded'
            ])->default('draft')->index();
            $table->uuid('created_by_user_id')->index();
            $table->uuid('approved_by_user_id')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // M-Pesa Integration
            $table->string('mpesa_transaction_id')->nullable()->index();
            $table->string('mpesa_receipt_number')->nullable()->index();
            $table->string('phone_number')->nullable();
            $table->enum('mpesa_type', ['C2B', 'B2C', 'STK_PUSH', 'MANUAL'])->nullable()->index();
            
            // Financial Details
            $table->decimal('fees', 10, 2)->default(0.00);
            $table->decimal('net_amount', 15, 2); // amount - fees
            $table->string('currency', 3)->default('KES');
            $table->decimal('exchange_rate', 10, 4)->default(1.0000);
            
            // Reconciliation
            $table->boolean('is_reconciled')->default(false)->index();
            $table->timestamp('reconciled_at')->nullable();
            $table->uuid('reconciled_by_user_id')->nullable()->index();
            $table->text('reconciliation_notes')->nullable();
            
            // Audit and Tracking
            $table->boolean('is_voided')->default(false)->index();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by_user_id')->nullable()->index();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable(); // Additional flexible data
            
            // Performance Optimization
            $table->index(['type', 'status', 'transaction_date']);
            $table->index(['payment_method', 'status']);
            $table->index(['created_by_user_id', 'transaction_date']);
            $table->index(['linked_type', 'linked_id']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};