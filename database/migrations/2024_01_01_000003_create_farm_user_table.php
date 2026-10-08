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
        Schema::create('farm_user', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('farm_id');
            $table->enum('role', ['owner', 'manager', 'worker', 'observer'])->default('worker');
            $table->json('permissions')->nullable(); // Specific permissions array
            $table->decimal('salary', 10, 2)->nullable(); // Monthly salary
            $table->decimal('hourly_rate', 8, 2)->nullable(); // Hourly rate
            $table->date('hire_date')->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'casual', 'contract'])->default('casual');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->text('responsibilities')->nullable();
            $table->json('working_hours')->nullable(); // Schedule
            $table->uuid('invited_by')->nullable(); // Who added this worker
            $table->timestamp('invitation_sent_at')->nullable();
            $table->timestamp('invitation_accepted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            $table->foreign('invited_by')->references('id')->on('users')->onDelete('set null');

            // Unique constraint - user can only have one role per farm
            $table->unique(['user_id', 'farm_id']);

            // Indexes
            $table->index(['farm_id', 'role']);
            $table->index(['user_id']);
            $table->index(['status']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farm_user');
    }
};