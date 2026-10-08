<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenant_registry', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id')->unique();
            $table->string('schema_name')->unique(); // PostgreSQL schema name
            $table->string('database_name')->nullable(); // If using database-per-tenant
            $table->enum('tenancy_type', ['schema', 'database'])->default('schema');
            $table->enum('status', ['provisioning', 'active', 'archived', 'failed', 'migrating'])->default('provisioning');
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('last_migration_at')->nullable();
            $table->string('migration_version')->nullable();
            $table->json('provisioning_log')->nullable(); // Track provisioning steps
            $table->json('settings')->nullable(); // Tenant-specific settings
            $table->text('failure_reason')->nullable();
            $table->integer('retry_count')->default(0);
            $table->uuid('provisioned_by')->nullable(); // User who triggered provisioning
            $table->timestamps();

            // Foreign keys
            $table->foreign('farm_id')->references('id')->on('farms')->onDelete('cascade');
            $table->foreign('provisioned_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index(['status']);
            $table->index(['schema_name']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_registry');
    }
};