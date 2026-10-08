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
        Schema::create('crop_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crop_cycle_id');
            $table->string('task_name');
            $table->enum('task_type', [
                'planting', 'watering', 'fertilizing', 'weeding', 'pest_control', 
                'disease_management', 'pruning', 'harvesting', 'general', 'other'
            ])->default('general');
            $table->text('description')->nullable();
            $table->date('scheduled_date');
            $table->time('scheduled_time')->nullable();
            $table->date('completed_date')->nullable();
            $table->time('completed_time')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled', 'overdue'])
                  ->default('scheduled');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->integer('estimated_duration')->nullable()->comment('Duration in minutes');
            $table->integer('actual_duration')->nullable()->comment('Duration in minutes');
            $table->uuid('assigned_to')->nullable()->comment('Worker/User ID');
            $table->text('completion_notes')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->json('task_data')->nullable()->comment('Additional task-specific data');
            $table->timestamps();

            $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('cascade');
            $table->index(['crop_cycle_id', 'status']);
            $table->index(['scheduled_date', 'status']);
            $table->index(['task_type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_tasks');
    }
};
