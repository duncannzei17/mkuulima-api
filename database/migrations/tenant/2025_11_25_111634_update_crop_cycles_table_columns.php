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

        if (Schema::hasColumn('crop_cycles', 'planting_date') && ! Schema::hasColumn('crop_cycles', 'start_date')) {
            Schema::table('crop_cycles', fn (Blueprint $table) => $table->renameColumn('planting_date', 'start_date'));
        }

        $add = function (string $column, callable $definition): void {
            if (! Schema::hasColumn('crop_cycles', $column)) {
                Schema::table('crop_cycles', $definition);
            }
        };
        $add('land_area', fn (Blueprint $table) => $table->decimal('land_area', 10, 2)->nullable());
        $add('land_area_unit', fn (Blueprint $table) => $table->string('land_area_unit')->default('acres'));
        $add('has_nursery', fn (Blueprint $table) => $table->boolean('has_nursery')->default(false));
        $add('nursery_start_date', fn (Blueprint $table) => $table->date('nursery_start_date')->nullable());
        $add('expected_transplant_date', fn (Blueprint $table) => $table->date('expected_transplant_date')->nullable());
        $add('seed_quantity', fn (Blueprint $table) => $table->integer('seed_quantity')->nullable());
        $add('seed_unit', fn (Blueprint $table) => $table->string('seed_unit')->nullable());
        $add('notes', fn (Blueprint $table) => $table->text('notes')->nullable());
        $add('bed_ids', fn (Blueprint $table) => $table->json('bed_ids')->nullable());
        $add('planned_inputs', fn (Blueprint $table) => $table->json('planned_inputs')->nullable());
        $add('planned_tasks', fn (Blueprint $table) => $table->json('planned_tasks')->nullable());
        $add('season_status', fn (Blueprint $table) => $table->enum('season_status', [
            'planning', 'nursery', 'transplanted', 'growing', 'harvest', 'completed', 'archived',
        ])->default('planning'));
        $add('growth_progress', fn (Blueprint $table) => $table->decimal('growth_progress', 5, 2)->default(0));
        $add('health_rating', fn (Blueprint $table) => $table->integer('health_rating')->default(5));
        $add('task_completion', fn (Blueprint $table) => $table->integer('task_completion')->default(0));
    }

    public function down(): void
    {
        if (! Schema::hasTable('crop_cycles')) {
            return;
        }

        $columns = array_values(array_filter([
            'land_area', 'land_area_unit', 'has_nursery', 'nursery_start_date',
            'expected_transplant_date', 'seed_quantity', 'seed_unit', 'notes', 'bed_ids',
            'planned_inputs', 'planned_tasks', 'season_status', 'growth_progress',
            'health_rating', 'task_completion',
        ], fn (string $column): bool => Schema::hasColumn('crop_cycles', $column)));
        if ($columns !== []) {
            Schema::table('crop_cycles', fn (Blueprint $table) => $table->dropColumn($columns));
        }
        if (Schema::hasColumn('crop_cycles', 'start_date') && ! Schema::hasColumn('crop_cycles', 'planting_date')) {
            Schema::table('crop_cycles', fn (Blueprint $table) => $table->renameColumn('start_date', 'planting_date'));
        }
    }
};
