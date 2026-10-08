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
        Schema::create('pending_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Payment Details
            $table->string('payment_reference')->unique()->index();
            $table->enum('payment_type', [
                'labour_payment', 'supplier_payment', 'expense_reimbursement',
                'bonus_payment', 'advance_payment', 'refund_payment',
                'contractor_payment', 'service_payment'
            ])->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('KES');
            $table->text('payment_reason');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->index();
            
            // Recipient Information
            $table->string('recipient_name');
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_email')->nullable();
            $table->enum('preferred_payment_method', ['cash', 'mpesa', 'bank_transfer', 'cheque'])->index();
            $table->json('payment_details')->nullable(); // Bank details, M-Pesa info, etc.
            
            // Linking to Source Records
            $table->uuid('linked_id')->nullable()->index(); // ID of labour, expense, etc.
            $table->enum('linked_type', ['labour', 'expense', 'supplier_invoice', 'contract'])->nullable()->index();
            $table->json('linked_metadata')->nullable(); // Additional context data
            
            // Approval Workflow
            $table->enum('status', [
                'draft', 'pending_manager_approval', 'pending_owner_approval', 
                'approved', 'rejected', 'processing', 'completed', 'failed',
                'cancelled', 'on_hold'
            ])->default('draft')->index();
            $table->uuid('requested_by_user_id')->index();
            $table->timestamp('requested_at');
            $table->uuid('manager_approved_by_user_id')->nullable()->index();
            $table->timestamp('manager_approved_at')->nullable();
            $table->text('manager_approval_notes')->nullable();
            $table->uuid('owner_approved_by_user_id')->nullable()->index();
            $table->timestamp('owner_approved_at')->nullable();
            $table->text('owner_approval_notes')->nullable();
            
            // Rejection Handling
            $table->uuid('rejected_by_user_id')->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            // Scheduling
            $table->timestamp('scheduled_payment_date')->nullable()->index();
            $table->boolean('is_recurring')->default(false)->index();
            $table->enum('recurring_frequency', ['weekly', 'monthly', 'quarterly'])->nullable();
            $table->integer('recurring_count')->nullable(); // How many times to repeat
            $table->integer('completed_recurrences')->default(0);
            
            // Payment Execution
            $table->uuid('transaction_id')->nullable()->index(); // Links to completed transaction
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('set null');
            $table->timestamp('payment_initiated_at')->nullable();
            $table->timestamp('payment_completed_at')->nullable();
            $table->text('payment_failure_reason')->nullable();
            $table->integer('payment_retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            
            // Budget Control
            $table->decimal('budget_allocated', 15, 2)->nullable();
            $table->string('budget_category')->nullable()->index();
            $table->boolean('exceeds_budget')->default(false)->index();
            $table->text('budget_override_reason')->nullable();
            
            // Notifications
            $table->json('notification_settings')->nullable(); // Email, SMS preferences
            $table->timestamp('last_reminder_sent_at')->nullable();
            $table->integer('reminder_count')->default(0);
            
            // Audit and Documentation
            $table->json('supporting_documents')->nullable(); // File paths, receipts
            $table->json('approval_history')->nullable(); // Track all status changes
            $table->text('internal_notes')->nullable();
            
            // Performance Indexes
            $table->index(['payment_type', 'status']);
            $table->index(['requested_by_user_id', 'requested_at']);
            $table->index(['status', 'priority', 'scheduled_payment_date']);
            $table->index(['linked_type', 'linked_id']);
            $table->index(['recipient_phone', 'status']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_payments');
    }
};