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
        Schema::create('farm_wallet', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            // Farm Association
            $table->uuid('farm_id')->index();
            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            
            // Wallet Balances
            $table->decimal('cash_balance', 15, 2)->default(0.00);
            $table->decimal('mpesa_balance', 15, 2)->default(0.00);
            $table->decimal('bank_balance', 15, 2)->default(0.00);
            $table->decimal('total_balance', 15, 2)->default(0.00)->index();
            $table->string('currency', 3)->default('KES');
            
            // Balance History and Tracking
            $table->decimal('opening_balance', 15, 2)->default(0.00);
            $table->decimal('closing_balance_previous', 15, 2)->default(0.00);
            $table->timestamp('last_balance_update')->nullable();
            $table->uuid('last_updated_by_user_id')->nullable()->index();
            
            // Monthly Aggregates (Performance Optimization)
            $table->decimal('monthly_income', 15, 2)->default(0.00);
            $table->decimal('monthly_expenses', 15, 2)->default(0.00);
            $table->decimal('monthly_net_flow', 15, 2)->default(0.00);
            $table->date('current_month')->index();
            
            // Reconciliation Status
            $table->boolean('is_reconciled')->default(true)->index();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->uuid('reconciled_by_user_id')->nullable()->index();
            $table->decimal('reconciliation_difference', 10, 2)->default(0.00);
            $table->text('reconciliation_notes')->nullable();
            
            // Limits and Controls
            $table->decimal('daily_spending_limit', 15, 2)->nullable();
            $table->decimal('weekly_spending_limit', 15, 2)->nullable();
            $table->decimal('monthly_spending_limit', 15, 2)->nullable();
            $table->decimal('current_daily_spending', 15, 2)->default(0.00);
            $table->decimal('current_weekly_spending', 15, 2)->default(0.00);
            $table->decimal('current_monthly_spending', 15, 2)->default(0.00);
            
            // Alert Thresholds
            $table->decimal('low_balance_alert_threshold', 15, 2)->default(1000.00);
            $table->decimal('negative_balance_alert_threshold', 15, 2)->default(0.00);
            $table->boolean('alerts_enabled')->default(true);
            $table->timestamp('last_alert_sent_at')->nullable();
            
            // Performance Metrics
            $table->decimal('average_daily_income', 10, 2)->default(0.00);
            $table->decimal('average_daily_expense', 10, 2)->default(0.00);
            $table->decimal('cash_flow_variance', 10, 2)->default(0.00);
            $table->integer('days_with_positive_flow')->default(0);
            $table->integer('days_with_negative_flow')->default(0);
            
            // External Account Links
            $table->string('mpesa_business_shortcode')->nullable();
            $table->string('mpesa_till_number')->nullable();
            $table->json('bank_account_details')->nullable();
            $table->json('mobile_money_accounts')->nullable();
            
            // Audit and Security
            $table->json('balance_change_log')->nullable(); // Track major changes
            $table->boolean('is_locked')->default(false)->index();
            $table->timestamp('locked_at')->nullable();
            $table->uuid('locked_by_user_id')->nullable()->index();
            $table->text('lock_reason')->nullable();
            
            // Backup and Recovery
            $table->json('daily_snapshots')->nullable(); // Store last 30 days of balances
            $table->timestamp('last_backup_at')->nullable();
            $table->json('backup_metadata')->nullable();
            
            // Performance Indexes
            $table->index(['farm_id', 'current_month']);
            $table->index(['total_balance', 'is_reconciled']);
            $table->index(['is_locked', 'alerts_enabled']);
            $table->index(['last_balance_update', 'farm_id']);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farm_wallet');
    }
};