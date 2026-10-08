<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crop_cycles')) {
            return;
        }

        $add = function (string $column, callable $definition): void {
            if (! Schema::hasColumn('crop_cycles', $column)) {
                Schema::table('crop_cycles', $definition);
            }
        };
        $add('general_notes', fn (Blueprint $table) => $table->text('general_notes')->nullable());
        $add('planting_notes', fn (Blueprint $table) => $table->text('planting_notes')->nullable());
        $add('season_name', fn (Blueprint $table) => $table->string('season_name')->nullable());
        $add('area_unit', fn (Blueprint $table) => $table->string('area_unit')->default('acres'));
        $add('yield_unit', fn (Blueprint $table) => $table->string('yield_unit')->default('kg'));
        $add('expected_yield', fn (Blueprint $table) => $table->decimal('expected_yield', 12, 2)->nullable());
        $add('actual_yield', fn (Blueprint $table) => $table->decimal('actual_yield', 12, 2)->nullable());
        $add('actual_transplant_date', fn (Blueprint $table) => $table->date('actual_transplant_date')->nullable());
        $add('seedlings_transplanted', fn (Blueprint $table) => $table->integer('seedlings_transplanted')->nullable());
        $add('estimated_cost', fn (Blueprint $table) => $table->decimal('estimated_cost', 14, 2)->nullable());
        $add('actual_cost', fn (Blueprint $table) => $table->decimal('actual_cost', 14, 2)->nullable());
        $add('estimated_revenue', fn (Blueprint $table) => $table->decimal('estimated_revenue', 14, 2)->nullable());
        $add('actual_revenue', fn (Blueprint $table) => $table->decimal('actual_revenue', 14, 2)->nullable());
        $add('health_status', fn (Blueprint $table) => $table->string('health_status')->default('good'));
        $add('weather_conditions', fn (Blueprint $table) => $table->json('weather_conditions')->nullable());
        $add('irrigation_required', fn (Blueprint $table) => $table->boolean('irrigation_required')->default(false));
        $add('completed_at', fn (Blueprint $table) => $table->timestamp('completed_at')->nullable());
    }

    public function down(): void
    {
        if (! Schema::hasTable('crop_cycles')) {
            return;
        }

        $columns = array_values(array_filter([
            'general_notes', 'planting_notes', 'season_name', 'area_unit', 'yield_unit',
            'expected_yield', 'actual_yield', 'actual_transplant_date',
            'seedlings_transplanted', 'estimated_cost', 'actual_cost', 'estimated_revenue',
            'actual_revenue', 'health_status', 'weather_conditions', 'irrigation_required',
            'completed_at',
        ], fn (string $column): bool => Schema::hasColumn('crop_cycles', $column)));
        if ($columns !== []) {
            Schema::table('crop_cycles', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
