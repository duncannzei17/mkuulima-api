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
        Schema::create('weather_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('user_id');
            $table->string('alert_type'); // heavy_rain, drought, extreme_temperature, strong_wind, frost, etc.
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->string('title');
            $table->text('message');
            $table->json('weather_data')->nullable(); // Current weather conditions that triggered alert
            $table->json('conditions_met'); // Which specific conditions were met
            $table->json('threshold_values')->nullable(); // Threshold values that were exceeded
            $table->json('recommended_actions')->nullable(); // Recommended actions for farmer
            $table->enum('status', ['active', 'acknowledged', 'dismissed', 'expired'])->default('active');
            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['farm_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['alert_type', 'severity']);
            $table->index('triggered_at');
            $table->index('expires_at');

            // Foreign keys
            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('weather_alerts');
    }
};
