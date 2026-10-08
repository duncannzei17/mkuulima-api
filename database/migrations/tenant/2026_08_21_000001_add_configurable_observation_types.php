<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('field_observations')) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('ALTER TABLE field_observations DROP CONSTRAINT IF EXISTS field_observations_observation_type_check');
                DB::statement('ALTER TABLE field_observations ALTER COLUMN observation_type TYPE VARCHAR(80)');
            } else {
                Schema::table('field_observations', function (Blueprint $table) {
                    $table->string('observation_type', 80)->change();
                });
            }
        }

        if (Schema::hasTable('observation_types')) {
            return;
        }

        Schema::create('observation_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_types');
    }
};
