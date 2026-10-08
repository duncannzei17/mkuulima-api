<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->enum('period_type', ['monthly', 'quarterly', 'seasonal', 'yearly', 'custom']);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('scope_type', ['farm_wide', 'crop_specific', 'category_specific', 'custom']);
            $table->uuid('crop_cycle_id')->nullable();
            $table->uuid('category_id')->nullable();
            $table->decimal('total_budget', 12, 2);
            $table->decimal('allocated_amount', 12, 2)->default(0);
            $table->decimal('spent_amount', 12, 2)->default(0);
            $table->decimal('committed_amount', 12, 2)->default(0);
            $table->decimal('variance', 12, 2)->default(0);
            $table->json('line_items')->nullable();
            $table->enum('status', ['draft', 'active', 'locked', 'closed', 'archived'])->default('draft');
            $table->boolean('auto_approve_under_budget')->default(false);
            $table->boolean('block_over_budget')->default(false);
            $table->decimal('approval_threshold', 10, 2)->nullable();
            $table->integer('alert_percentage')->default(80);
            $table->boolean('email_alerts')->default(true);
            $table->json('notification_recipients')->nullable();
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('version')->default(1);
            $table->uuid('parent_budget_id')->nullable();
            $table->json('revision_notes')->nullable();
            $table->decimal('forecast_accuracy', 5, 2)->nullable();
            $table->text('performance_notes')->nullable();
            $table->timestamps();

            $table->foreign('category_id')->references('id')->on('expense_categories')->cascadeOnDelete();
            $table->index('period_type');
            $table->index('scope_type');
            $table->index('status');
            $table->index('start_date');
            $table->index('end_date');
            $table->index('created_by');
            $table->index('crop_cycle_id');
            $table->index('category_id');
            $table->index(['status', 'start_date']);
            $table->index(['period_type', 'status']);
        });

        if (Schema::hasTable('crop_cycles')) {
            Schema::table('expense_budgets', function (Blueprint $table) {
                $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_budgets');
    }
};
