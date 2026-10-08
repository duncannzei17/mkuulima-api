<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('sales_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sale_id');
            $table->uuid('approved_by');
            $table->enum('action', ['approved', 'rejected']);
            $table->text('reason')->nullable(); // Required for rejections, optional for approvals
            $table->json('changes_made')->nullable(); // Track what was changed during approval
            $table->timestamp('action_taken_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->index('sale_id');
            $table->index('approved_by');
            $table->index('action');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales_approvals');
    }
};