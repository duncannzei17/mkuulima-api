<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sales_deductions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sale_id');
            $table->enum('deduction_type', [
                'transport_fare',
                'airtime',
                'packaging',
                'labour',
                'handling_fees',
                'market_fees',
                'commission',
                'taxes',
                'storage',
                'loading_offloading',
                'weighing',
                'grading_sorting',
                'other'
            ]);
            $table->decimal('amount', 10, 2);
            $table->text('description')->nullable();
            $table->string('recipient')->nullable(); // Who received the payment
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->index('sale_id');
            $table->index('deduction_type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales_deductions');
    }
};