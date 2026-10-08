<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buyers') && ! Schema::hasColumn('buyers', 'phone_encrypted')) {
            Schema::table('buyers', function (Blueprint $table) {
                $table->text('phone_encrypted')->nullable()->after('phone');
                $table->string('phone_last_four', 4)->nullable()->after('phone_encrypted')->index();
            });

            DB::table('buyers')->whereNotNull('phone')->orderBy('id')->each(function ($buyer) {
                DB::table('buyers')->where('id', $buyer->id)->update([
                    'phone_encrypted' => Crypt::encryptString($buyer->phone),
                    'phone_last_four' => substr($buyer->phone, -4),
                    'phone' => null,
                ]);
            });
        }

        foreach (['markets', 'sell_orders', 'market_insights'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
            }
        }

        if (Schema::hasTable('sell_orders') && ! Schema::hasColumn('sell_orders', 'sale_id')) {
            Schema::table('sell_orders', function (Blueprint $table) {
                $table->uuid('sale_id')->nullable()->after('harvest_id')->unique();
                $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sell_orders') && Schema::hasColumn('sell_orders', 'sale_id')) {
            Schema::table('sell_orders', function (Blueprint $table) {
                $table->dropForeign(['sale_id']);
                $table->dropColumn('sale_id');
            });
        }

        foreach (['markets', 'sell_orders', 'market_insights'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropSoftDeletes());
            }
        }

        if (Schema::hasTable('buyers') && Schema::hasColumn('buyers', 'phone_encrypted')) {
            DB::table('buyers')->whereNotNull('phone_encrypted')->orderBy('id')->each(function ($buyer) {
                DB::table('buyers')->where('id', $buyer->id)->update([
                    'phone' => Crypt::decryptString($buyer->phone_encrypted),
                ]);
            });
            Schema::table('buyers', fn (Blueprint $table) => $table->dropColumn(['phone_encrypted', 'phone_last_four']));
        }
    }
};
