<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('type', ['individual', 'aggregator', 'broker', 'neighbour', 'supermarket', 'restaurant', 'hotel', 'wholesaler', 'processor', 'export_company', 'other'])->default('individual');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('location')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->enum('payment_terms', ['immediate', 'weekly', 'monthly', 'custom'])->default('immediate');
            $table->integer('payment_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('contact_info')->nullable();
            $table->decimal('total_purchases', 15, 2)->default(0);
            $table->integer('total_transactions')->default(0);
            $table->decimal('average_purchase_value', 12, 2)->default(0);
            $table->date('last_purchase_date')->nullable();
            $table->decimal('reliability_score', 3, 2)->default(5.0);
            $table->uuid('created_by');
            $table->timestamps();

            $table->index(['name', 'type']);
            $table->index(['is_active', 'type']);
            $table->index('phone');
            $table->index('location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyers');
    }
};
