<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ussd_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id'); // links to ussd_sessions
            
            // Request identification
            $table->string('request_id', 100)->nullable(); // telco request ID if provided
            $table->integer('sequence_number')->default(1); // order within session
            
            // Request data
            $table->string('phone_number', 20)->index();
            $table->string('network_code', 10)->nullable();
            $table->text('user_input')->nullable(); // what user typed/selected
            $table->string('menu_selection', 50)->nullable(); // parsed menu choice
            $table->text('raw_request')->nullable(); // full telco request data
            
            // Response data
            $table->text('system_response')->nullable(); // what we sent back
            $table->enum('response_type', ['CON', 'END'])->nullable(); // continue or end
            $table->text('display_text')->nullable(); // actual USSD menu text shown
            $table->string('next_menu', 50)->nullable(); // where user should go next
            
            // Processing details
            $table->string('action_taken', 100)->nullable(); // expense_added, harvest_recorded, etc.
            $table->json('action_data')->nullable(); // details of what was processed
            $table->string('api_endpoint', 200)->nullable(); // which module API was called
            $table->json('api_request')->nullable(); // data sent to module API
            $table->json('api_response')->nullable(); // response from module API
            
            // Timing and performance
            $table->timestamp('received_at')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->integer('processing_time_ms')->nullable(); // processing time in milliseconds
            
            // Error tracking
            $table->boolean('has_error')->default(false)->index();
            $table->string('error_type', 50)->nullable(); // validation, api, system, timeout
            $table->text('error_message')->nullable();
            $table->json('error_details')->nullable(); // full error context
            
            // Quality and monitoring
            $table->boolean('user_friendly_response')->default(true); // did we give a good UX?
            $table->enum('outcome', ['success', 'error', 'validation_failed', 'timeout'])->nullable();
            $table->text('notes')->nullable(); // debugging notes
            
            $table->timestamps();
            
            // Indexes for performance and analysis
            $table->index(['session_id', 'sequence_number']);
            $table->index(['phone_number', 'created_at']);
            $table->index(['action_taken', 'created_at']);
            $table->index(['has_error', 'error_type']);
            $table->index(['response_type', 'outcome']);
            $table->index(['processing_time_ms']); // for performance monitoring
            
            // Foreign key constraint
            $table->foreign('session_id')->references('id')->on('ussd_sessions')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_requests');
    }
};