<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Workers table
        Schema::create('workers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('phone', 20)->nullable();
            $table->string('id_number', 50)->nullable();
            $table->string('role')->default('casual'); // permanent, casual, seasonal, contractor
            $table->string('status')->default('active'); // active, inactive
            $table->decimal('default_daily_rate', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['status', 'role']);
            $table->index('created_by');
        });

        // Labour Entries table
        Schema::create('labour_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('worker_id')->nullable();
            $table->string('worker_name', 100); // denormalized for quick reference
            $table->date('labour_date');
            $table->string('labour_type'); // weeding, bed_preparation, transplanting, etc.
            $table->string('payment_type'); // daily, piece_rate, group_labour, multi_day
            $table->decimal('amount', 14, 2);
            $table->integer('units')->nullable(); // for piece_rate
            $table->integer('number_of_workers')->default(1);
            $table->uuid('crop_cycle_id')->nullable();
            $table->uuid('bed_id')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->string('payment_status')->default('unpaid'); // unpaid, partial, paid
            $table->date('payment_date')->nullable();
            $table->string('payment_method')->nullable(); // cash, mpesa, bank_transfer
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('worker_id')->references('id')->on('workers')->onDelete('set null');
            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');

            $table->index(['labour_date', 'status']);
            $table->index(['worker_id', 'status']);
            $table->index(['crop_cycle_id', 'status']);
            $table->index('payment_status');
        });

        // Labour Approvals table (audit trail)
        Schema::create('labour_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('labour_entry_id');
            $table->string('action'); // approved, rejected
            $table->text('reason')->nullable();
            $table->uuid('approved_by');
            $table->timestamps();

            $table->foreign('labour_entry_id')->references('id')->on('labour_entries')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('cascade');
            $table->index('labour_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labour_approvals');
        Schema::dropIfExists('labour_entries');
        Schema::dropIfExists('workers');
    }
};
