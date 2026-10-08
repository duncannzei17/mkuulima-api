<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sale_id');
            $table->uuid('approved_by');
            $table->enum('action', ['approved', 'rejected']);
            $table->text('reason')->nullable();
            $table->json('changes_made')->nullable();
            $table->timestamp('action_taken_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            $table->index('sale_id');
            $table->index('approved_by');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_approvals');
    }
};
