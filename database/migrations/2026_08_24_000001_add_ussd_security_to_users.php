<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ussd_pin_hash')->nullable();
            $table->timestamp('ussd_pin_enabled_at')->nullable();
            $table->unsignedTinyInteger('ussd_failed_attempts')->default(0);
            $table->timestamp('ussd_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'ussd_pin_hash',
                'ussd_pin_enabled_at',
                'ussd_failed_attempts',
                'ussd_locked_until',
            ]);
        });
    }
};
