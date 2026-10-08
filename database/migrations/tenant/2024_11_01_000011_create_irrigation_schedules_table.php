<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irrigation_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id')->index();
            $table->uuid('irrigation_system_id')->nullable()->index();
            $table->string('name');
            $table->time('start_time');
            $table->integer('duration_minutes')->default(15);
            $table->json('days_of_week')->nullable();
            $table->enum('status', ['active', 'paused', 'completed'])->default('active')->index();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->decimal('water_amount_liters', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable()->index();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'status']);
            $table->index(['farm_id', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irrigation_schedules');
    }
};
