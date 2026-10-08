<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesLegacySchemaMigrationTest extends TestCase
{
    public function test_it_reconciles_and_sanitizes_a_legacy_sales_table(): void
    {
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sales');
        Schema::create('sales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->decimal('quantity_sold', 12, 3);
            $table->decimal('price_per_unit', 10, 2);
            $table->decimal('gross_income', 12, 2);
            $table->decimal('total_deductions', 12, 2)->default(0);
            $table->decimal('net_income', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('amount_outstanding', 12, 2)->default(0);
            $table->string('payment_status');
            $table->string('status');
            $table->string('sale_type');
            $table->string('payment_method');
        });

        DB::table('sales')->insert([
            'id' => '019f0000-0000-7000-8000-000000000001',
            'quantity_sold' => 10,
            'price_per_unit' => 100,
            'gross_income' => 50,
            'total_deductions' => 1200,
            'net_income' => -1150,
            'amount_paid' => 2000,
            'amount_outstanding' => -3150,
            'payment_status' => 'unknown',
            'status' => 'legacy',
            'sale_type' => 'unknown',
            'payment_method' => 'mpesa',
        ]);

        $migration = require database_path('migrations/tenant/2026_09_14_000001_reconcile_legacy_sales_schema.php');
        $migration->up();

        $sale = DB::table('sales')->first();
        $this->assertTrue(Schema::hasColumn('sales', 'deleted_at'));
        $this->assertTrue(Schema::hasTable('sale_payments'));
        $this->assertSame(1000.0, (float) $sale->gross_income);
        $this->assertSame(1000.0, (float) $sale->total_deductions);
        $this->assertSame(0.0, (float) $sale->net_income);
        $this->assertSame(0.0, (float) $sale->amount_paid);
        $this->assertSame(0.0, (float) $sale->amount_outstanding);
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('pending', $sale->status);
        $this->assertSame('market', $sale->sale_type);
        $this->assertSame('m_pesa', $sale->payment_method);
    }
}
