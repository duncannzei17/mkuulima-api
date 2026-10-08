<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('labour_entries')) {
            return;
        }
        if (! Schema::hasColumn('labour_entries', 'paid_amount')) {
            Schema::table('labour_entries', fn (Blueprint $table) => $table->decimal('paid_amount', 14, 2)->default(0));
        }
        if (! Schema::hasColumn('labour_entries', 'payment_reference')) {
            Schema::table('labour_entries', fn (Blueprint $table) => $table->string('payment_reference')->nullable());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('labour_entries')) {
            return;
        }
        $columns = array_values(array_filter(
            ['paid_amount', 'payment_reference'],
            fn (string $column): bool => Schema::hasColumn('labour_entries', $column)
        ));
        if ($columns !== []) {
            Schema::table('labour_entries', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
