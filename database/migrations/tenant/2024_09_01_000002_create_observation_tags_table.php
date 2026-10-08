<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('observation_id');
            $table->string('tag', 50);
            $table->string('tag_category', 30)->default('general');
            $table->timestamps();
            
            $table->foreign('observation_id')->references('id')->on('field_observations')->onDelete('cascade');
            
            $table->index(['observation_id', 'tag']);
            $table->index(['tag_category', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_tags');
    }
};