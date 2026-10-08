<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE profitability_snapshots ALTER COLUMN profit_margin TYPE DECIMAL(12, 2)');
        DB::statement('ALTER TABLE profitability_snapshots ALTER COLUMN roi_percentage TYPE DECIMAL(12, 2)');
        DB::statement('ALTER TABLE profitability_snapshots ALTER COLUMN yield_efficiency TYPE DECIMAL(12, 2)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE profitability_snapshots ALTER COLUMN profit_margin TYPE DECIMAL(5, 2)');
        DB::statement('ALTER TABLE profitability_snapshots ALTER COLUMN roi_percentage TYPE DECIMAL(5, 2)');
        DB::statement('ALTER TABLE profitability_snapshots ALTER COLUMN yield_efficiency TYPE DECIMAL(5, 2)');
    }
};
