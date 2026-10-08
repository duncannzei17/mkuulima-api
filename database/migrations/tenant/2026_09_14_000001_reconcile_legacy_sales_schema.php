<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (! Schema::hasColumn('sales', 'deleted_at')) {
            Schema::table('sales', fn (Blueprint $table) => $table->softDeletes());
        }

        if (! Schema::hasTable('sale_payments')) {
            Schema::create('sale_payments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('sale_id');
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 50);
                $table->date('payment_date');
                $table->string('reference', 255)->nullable();
                $table->text('notes')->nullable();
                $table->uuid('recorded_by');
                $table->timestamps();
                $table->index(['sale_id', 'payment_date']);
                $table->index('recorded_by');
            });
        }

        $validStatuses = ['pending', 'approved', 'rejected'];
        $validSaleTypes = ['farmgate', 'market', 'door_to_door', 'pickup', 'delivery', 'online', 'bulk_order', 'spot_sale'];
        $validPaymentMethods = ['cash', 'm_pesa', 'bank_transfer', 'cheque', 'credit', 'mobile_money', 'crypto', 'other'];

        DB::table('sales')
            ->select([
                'id', 'quantity_sold', 'price_per_unit', 'total_deductions', 'amount_paid',
                'status', 'sale_type', 'payment_method',
            ])
            ->orderBy('id')
            ->chunk(500, function ($sales) use ($validStatuses, $validSaleTypes, $validPaymentMethods): void {
                foreach ($sales as $sale) {
                    $grossIncome = round(max(0, (float) $sale->quantity_sold) * max(0, (float) $sale->price_per_unit), 2);
                    $totalDeductions = min($grossIncome, max(0, (float) $sale->total_deductions));
                    $netIncome = round($grossIncome - $totalDeductions, 2);
                    $amountPaid = min($netIncome, max(0, (float) $sale->amount_paid));
                    $amountOutstanding = round($netIncome - $amountPaid, 2);
                    $paymentMethod = $sale->payment_method === 'mpesa' ? 'm_pesa' : $sale->payment_method;

                    DB::table('sales')->where('id', $sale->id)->update([
                        'gross_income' => $grossIncome,
                        'total_deductions' => $totalDeductions,
                        'net_income' => $netIncome,
                        'amount_paid' => $amountPaid,
                        'amount_outstanding' => $amountOutstanding,
                        'payment_status' => $amountOutstanding <= 0
                            ? 'paid'
                            : ($amountPaid > 0 ? 'partial' : 'pending'),
                        'status' => in_array($sale->status, $validStatuses, true) ? $sale->status : 'pending',
                        'sale_type' => in_array($sale->sale_type, $validSaleTypes, true) ? $sale->sale_type : 'market',
                        'payment_method' => in_array($paymentMethod, $validPaymentMethods, true) ? $paymentMethod : 'other',
                    ]);
                }
            });
    }

    public function down(): void
    {
        // This reconciliation is intentionally irreversible: dropping audit data would
        // violate the sales history contract owned by the earlier schema migration.
    }
};
