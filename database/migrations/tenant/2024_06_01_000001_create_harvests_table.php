<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('harvests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('crop_cycle_id');
            $table->date('harvest_date');
            $table->decimal('total_quantity', 12, 3);
            $table->string('unit'); // kg, bunch, bag, crate, etc.
            $table->enum('grade_type', ['simple', 'detailed'])->default('simple');
            $table->enum('simple_grade', ['marketable', 'non_marketable'])->nullable();
            $table->enum('detailed_grade', ['A', 'B', 'C', 'reject'])->nullable();
            $table->uuid('worker_id')->nullable();
            $table->text('notes')->nullable();
            $table->json('photos')->nullable(); // Array of photo URLs
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->decimal('expected_quantity', 12, 3)->nullable();
            $table->decimal('variance_percentage', 8, 2)->nullable(); // Calculated field
            $table->json('weather_conditions')->nullable(); // Weather during harvest
            $table->decimal('moisture_content', 5, 2)->nullable(); // For grains
            $table->boolean('is_final_harvest')->default(false);
            $table->timestamps();

            // Foreign keys will be added separately if tables exist
            // $table->foreign('crop_cycle_id')->references('id')->on('crop_cycles')->onDelete('cascade');
            // $table->foreign('worker_id')->references('id')->on('workers')->onDelete('set null');
            $table->index(['harvest_date', 'status']);
            $table->index(['crop_cycle_id', 'harvest_date']);
            $table->index('status');
        });
    }

    public function down()
    {
        Schema::dropIfExists('harvests');
    }
};