<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#6B7280');
            $table->string('icon', 50)->nullable();
            $table->boolean('is_system_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by');
            $table->decimal('monthly_budget', 10, 2)->nullable();
            $table->decimal('seasonal_budget', 10, 2)->nullable();
            $table->decimal('total_spent', 10, 2)->default(0);
            $table->integer('expense_count')->default(0);
            $table->date('last_used_date')->nullable();
            $table->timestamps();

            $table->index('slug');
            $table->index('is_active');
            $table->index('is_system_default');
            $table->index('created_by');
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
