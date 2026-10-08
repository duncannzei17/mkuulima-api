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
        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Schedule Details
            $table->string('schedule_name');
            $table->text('description')->nullable();
            $table->enum('schedule_type', [
                'recurring_labour', 'recurring_supplier', 'loan_payment',
                'rent_payment', 'subscription', 'maintenance_contract',
                'insurance_premium', 'tax_payment'
            ])->index();
            
            // Payment Information
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('KES');
            $table->enum('payment_method', ['cash', 'mpesa', 'bank_transfer', 'cheque'])->index();
            
            // Recipient Details
            $table->string('recipient_name');
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_email')->nullable();
            $table->json('recipient_payment_details')->nullable();
            
            // Schedule Configuration
            $table->enum('frequency', ['daily', 'weekly', 'bi_weekly', 'monthly', 'quarterly', 'yearly'])->index();
            $table->integer('frequency_interval')->default(1); // Every N periods
            $table->date('start_date')->index();
            $table->date('end_date')->nullable()->index();
            $table->integer('total_occurrences')->nullable(); // Alternative to end_date
            $table->integer('completed_payments')->default(0);
            
            // Status and Control
            $table->enum('status', ['active', 'paused', 'completed', 'cancelled'])->default('active')->index();
            $table->boolean('auto_execute')->default(false)->index(); // Requires approval if false
            $table->enum('approval_required', ['none', 'manager', 'owner'])->default('owner');
            
            // Next Payment Details
            $table->timestamp('next_payment_date')->nullable()->index();
            $table->timestamp('last_payment_date')->nullable();
            $table->uuid('last_payment_id')->nullable()->index(); // Reference to transaction
            
            // Budget and Limits
            $table->decimal('monthly_budget_limit', 15, 2)->nullable();
            $table->decimal('annual_budget_limit', 15, 2)->nullable();
            $table->decimal('current_month_spent', 15, 2)->default(0.00);
            $table->decimal('current_year_spent', 15, 2)->default(0.00);
            
            // Notification Settings
            $table->json('notification_settings')->nullable(); // Who to notify, when
            $table->integer('remind_days_before')->default(3);
            $table->timestamp('last_reminder_sent')->nullable();
            
            // Failure Handling
            $table->integer('max_retry_attempts')->default(3);
            $table->integer('current_retry_count')->default(0);
            $table->timestamp('last_failure_date')->nullable();
            $table->text('last_failure_reason')->nullable();
            $table->enum('failure_action', ['pause', 'skip', 'manual_review'])->default('manual_review');
            
            // Linking
            $table->uuid('linked_id')->nullable()->index(); // Contract, agreement, etc.
            $table->string('linked_type')->nullable();
            $table->json('linked_metadata')->nullable();
            
            // Audit
            $table->uuid('created_by_user_id')->index();
            $table->uuid('last_modified_by_user_id')->nullable()->index();
            $table->json('modification_history')->nullable();
            
            // Performance Indexes
            $table->index(['status', 'next_payment_date']);
            $table->index(['schedule_type', 'frequency']);
            $table->index(['auto_execute', 'next_payment_date']);
            $table->index(['recipient_phone', 'status']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_schedules');
    }
};