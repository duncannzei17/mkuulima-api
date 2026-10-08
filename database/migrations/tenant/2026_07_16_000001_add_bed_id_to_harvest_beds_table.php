<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('harvest_beds') || Schema::hasColumn('harvest_beds', 'bed_id')) {
            return;
        }

        Schema::table('harvest_beds', function (Blueprint $table) {
            $table->uuid('bed_id')->nullable()->after('harvest_id')->index();
            $table->foreign('bed_id')->references('id')->on('beds')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('harvest_beds') || ! Schema::hasColumn('harvest_beds', 'bed_id')) {
            return;
        }

        Schema::table('harvest_beds', function (Blueprint $table) {
            $table->dropForeign(['bed_id']);
            $table->dropColumn('bed_id');
        });
    }
};
