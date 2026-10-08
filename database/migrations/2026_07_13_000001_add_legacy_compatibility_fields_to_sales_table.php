<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'farm_id')) {
                $table->uuid('farm_id')->nullable()->after('quality_feedback');
            }

            if (!Schema::hasColumn('sales', 'crop_id')) {
                $table->uuid('crop_id')->nullable()->after('farm_id');
            }

            if (!Schema::hasColumn('sales', 'recorded_by')) {
                $table->uuid('recorded_by')->nullable()->after('crop_id');
            }

            if (!Schema::hasColumn('sales', 'buyer_contact')) {
                $table->string('buyer_contact')->nullable()->after('buyer_name');
            }

            if (!Schema::hasColumn('sales', 'buyer_type')) {
                $table->string('buyer_type')->nullable()->after('buyer_contact');
            }

            if (!Schema::hasColumn('sales', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->nullable()->after('price_per_unit');
            }

            if (!Schema::hasColumn('sales', 'total_amount')) {
                $table->decimal('total_amount', 12, 2)->nullable()->after('gross_income');
            }

            if (!Schema::hasColumn('sales', 'currency')) {
                $table->string('currency', 3)->default('KES')->after('amount_outstanding');
            }

            if (!Schema::hasColumn('sales', 'quality_grade')) {
                $table->string('quality_grade')->nullable()->after('quality_feedback');
            }

            if (!Schema::hasColumn('sales', 'commission_rate')) {
                $table->decimal('commission_rate', 5, 2)->default(0)->after('total_deductions');
            }

            if (!Schema::hasColumn('sales', 'commission_amount')) {
                $table->decimal('commission_amount', 12, 2)->default(0)->after('commission_rate');
            }

            if (!Schema::hasColumn('sales', 'net_amount')) {
                $table->decimal('net_amount', 12, 2)->nullable()->after('net_income');
            }

            if (!Schema::hasColumn('sales', 'invoice_number')) {
                $table->string('invoice_number')->nullable()->after('notes');
            }

            if (!Schema::hasColumn('sales', 'delivery_status')) {
                $table->string('delivery_status')->default('pending')->after('delivery_info');
            }

            if (!Schema::hasColumn('sales', 'delivery_date')) {
                $table->date('delivery_date')->nullable()->after('delivery_status');
            }

            if (!Schema::hasColumn('sales', 'attachments')) {
                $table->json('attachments')->nullable()->after('quality_feedback');
            }

            if (!Schema::hasColumn('sales', 'tags')) {
                $table->json('tags')->nullable()->after('attachments');
            }

            if (!Schema::hasColumn('sales', 'metadata')) {
                $table->json('metadata')->nullable()->after('tags');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales')) {
            return;
        }

        Schema::table('sales', function (Blueprint $table) {
            $columns = [
                'farm_id',
                'crop_id',
                'recorded_by',
                'buyer_contact',
                'buyer_type',
                'unit_price',
                'total_amount',
                'currency',
                'quality_grade',
                'commission_rate',
                'commission_amount',
                'net_amount',
                'invoice_number',
                'delivery_status',
                'delivery_date',
                'attachments',
                'tags',
                'metadata',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
