<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales') && ! Schema::hasColumn('sales', 'deleted_at')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('sale_payments')) {
            Schema::create('sale_payments', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('sale_id');
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 50);
                $table->date('payment_date');
                $table->string('reference', 255)->nullable();
                $table->text('notes')->nullable();
                $table->uuid('recorded_by');
                $table->timestamps();

                $table->index(['sale_id', 'payment_date']);
                $table->index('recorded_by');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'deleted_at')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
