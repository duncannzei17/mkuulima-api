<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irrigation_systems', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id')->index();
            $table->string('name');
            $table->string('type', 50)->index();
            $table->string('zone_name')->nullable();
            $table->json('bed_ids')->nullable();
            $table->enum('status', ['active', 'scheduled', 'offline', 'maintenance'])->default('offline')->index();
            $table->decimal('capacity_lph', 10, 2)->default(0);
            $table->decimal('flow_rate_lph', 10, 2)->default(0);
            $table->decimal('pressure_psi', 8, 2)->default(0);
            $table->decimal('power_level', 5, 2)->default(100);
            $table->decimal('temperature_celsius', 5, 2)->nullable();
            $table->decimal('coverage_area', 10, 2)->default(0);
            $table->decimal('water_usage_today_liters', 10, 2)->default(0);
            $table->timestamp('last_maintenance_at')->nullable();
            $table->text('maintenance_notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->uuid('created_by')->nullable()->index();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['farm_id', 'type']);
            $table->index(['farm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('irrigation_systems');
    }
};
