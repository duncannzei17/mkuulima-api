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
        Schema::create('mpesa_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Transaction Linking
            $table->uuid('transaction_id')->nullable()->index();
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
            
            // M-Pesa Transaction Details
            $table->string('mpesa_transaction_id')->nullable()->index();
            $table->string('mpesa_receipt_number')->nullable()->index();
            $table->string('checkout_request_id')->nullable()->index(); // STK Push
            $table->string('merchant_request_id')->nullable()->index(); // STK Push
            
            // Transaction Type and Flow
            $table->enum('transaction_type', ['C2B', 'B2C', 'STK_PUSH', 'REVERSAL', 'BALANCE_INQUIRY'])->index();
            $table->enum('flow_direction', ['INCOMING', 'OUTGOING'])->index();
            
            // Parties Involved
            $table->string('sender_phone')->nullable()->index();
            $table->string('receiver_phone')->nullable()->index();
            $table->string('sender_name')->nullable();
            $table->string('receiver_name')->nullable();
            
            // Financial Details
            $table->decimal('amount', 15, 2);
            $table->decimal('transaction_cost', 10, 2)->default(0.00);
            $table->decimal('business_balance', 15, 2)->nullable();
            $table->string('currency', 3)->default('KES');
            
            // M-Pesa Response Details
            $table->string('result_code')->nullable()->index();
            $table->string('result_description')->nullable();
            $table->string('response_code')->nullable();
            $table->string('response_description')->nullable();
            
            // Callback and API Details
            $table->json('request_payload')->nullable(); // Original API request
            $table->json('response_payload')->nullable(); // M-Pesa API response
            $table->json('callback_payload')->nullable(); // Callback from M-Pesa
            $table->timestamp('callback_received_at')->nullable();
            
            // Processing Status
            $table->enum('status', [
                'initiated', 'pending', 'processing', 'completed', 'failed', 
                'timeout', 'cancelled', 'reversed', 'duplicate'
            ])->default('initiated')->index();
            $table->text('failure_reason')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            
            // Security and Validation
            $table->string('api_endpoint')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->boolean('is_duplicate')->default(false)->index();
            $table->string('duplicate_of')->nullable()->index(); // Reference to original transaction
            
            // Processing Times
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('processing_time_ms')->nullable(); // Time taken in milliseconds
            
            // Reconciliation
            $table->boolean('is_reconciled')->default(false)->index();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('reconciliation_notes')->nullable();
            
            // Audit Trail
            $table->uuid('created_by_user_id')->nullable()->index();
            $table->json('audit_trail')->nullable(); // Track status changes
            
            // Performance Indexes
            $table->index(['transaction_type', 'status']);
            $table->index(['sender_phone', 'created_at']);
            $table->index(['result_code', 'transaction_type']);
            $table->index(['status', 'created_at']);
            $table->index(['is_duplicate', 'mpesa_receipt_number']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_logs');
    }
};