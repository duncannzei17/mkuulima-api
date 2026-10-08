<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ussd_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id')->nullable(); // linked to farm after authentication
            $table->uuid('user_id')->nullable(); // linked to user after authentication
            
            // Session identification
            $table->string('session_id', 100)->unique()->index(); // telco session ID
            $table->string('phone_number', 20)->index(); // user's phone number
            $table->string('network_code', 10)->nullable(); // safaricom, airtel, telkom
            
            // Session state management
            $table->enum('status', ['active', 'expired', 'completed', 'abandoned'])->default('active')->index();
            $table->string('current_menu', 50)->default('main')->index(); // main, expenses, labour, etc.
            $table->integer('current_step')->default(1); // step within menu
            $table->string('current_flow', 50)->nullable(); // expense_add, labour_add, harvest_record, etc.
            
            // Language and localization
            $table->enum('language', ['sw', 'en'])->default('sw'); // swahili or english
            $table->json('menu_history')->nullable(); // breadcrumb trail
            
            // Temporary data storage during session
            $table->json('temp_data')->nullable(); // storing partial form data
            $table->json('context_data')->nullable(); // additional context for flows
            $table->text('last_input')->nullable(); // user's last input
            $table->text('last_output')->nullable(); // system's last response
            
            // Security and validation
            $table->boolean('is_authenticated')->default(false)->index();
            $table->string('pin_hash', 255)->nullable(); // hashed PIN for quick auth
            $table->integer('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            
            // Performance and monitoring
            $table->integer('total_requests')->default(0);
            $table->timestamp('started_at')->index();
            $table->timestamp('last_activity_at')->index();
            $table->timestamp('expires_at')->index();
            $table->integer('duration_seconds')->nullable(); // calculated on completion
            
            // Error tracking
            $table->integer('error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            
            // Completion tracking
            $table->boolean('completed_successfully')->default(false);
            $table->string('completion_reason', 100)->nullable(); // user_ended, timeout, error, completed
            $table->json('completion_data')->nullable(); // summary of what was accomplished
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['phone_number', 'status']);
            $table->index(['farm_id', 'created_at']);
            $table->index(['current_menu', 'status']);
            $table->index(['expires_at', 'status']);
            $table->index(['is_authenticated', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_sessions');
    }
};