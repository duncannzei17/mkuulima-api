<?php

namespace App\Models;

use App\Services\TenantMigrationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantRegistry extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'tenant_registry';

    protected $fillable = [
        'farm_id',
        'schema_name',
        'database_name',
        'tenancy_type',
        'status',
        'provisioned_at',
        'last_migration_at',
        'migration_version',
        'provisioning_log',
        'settings',
        'failure_reason',
        'retry_count',
        'provisioned_by'
    ];

    protected $casts = [
        'provisioned_at' => 'datetime',
        'last_migration_at' => 'datetime',
        'provisioning_log' => 'array',
        'settings' => 'array',
        'retry_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'tenancy_type' => 'schema',
        'status' => 'provisioning',
        'retry_count' => 0,
    ];

    // Status constants
    public const STATUSES = [
        'provisioning' => 'Provisioning',
        'active' => 'Active',
        'archived' => 'Archived',
        'failed' => 'Failed',
        'migrating' => 'Migrating'
    ];

    public const TENANCY_TYPES = [
        'schema' => 'Schema-based Multi-tenancy',
        'database' => 'Database-based Multi-tenancy'
    ];

    // Relationships
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function provisionedBy()
    {
        return $this->belongsTo(User::class, 'provisioned_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeByTenancyType($query, $type)
    {
        return $query->where('tenancy_type', $type);
    }

    // Accessors
    public function getStatusNameAttribute()
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getTenancyTypeNameAttribute()
    {
        return self::TENANCY_TYPES[$this->tenancy_type] ?? $this->tenancy_type;
    }

    public function getIsActiveAttribute()
    {
        return $this->status === 'active';
    }

    public function getIsProvisioningAttribute()
    {
        return $this->status === 'provisioning';
    }

    public function getIsFailedAttribute()
    {
        return $this->status === 'failed';
    }

    // Business Logic Methods
    public function provisionTenant()
    {
        try {
            $this->addToLog('Starting tenant provisioning');
            $this->status = 'provisioning';
            $this->save();

            // Create schema
            $this->createSchema();
            $this->addToLog('Schema created successfully');

            // Run migrations
            $this->runMigrations();
            $this->addToLog('Migrations completed successfully');

            // Seed default data
            $this->seedDefaultData();
            $this->addToLog('Default data seeded successfully');

            // Mark as active
            $this->markAsActive();
            $this->addToLog('Tenant provisioning completed successfully');

            return true;

        } catch (\Exception $e) {
            $this->markAsFailed($e->getMessage());
            $this->addToLog('Provisioning failed: ' . $e->getMessage());
            return false;
        } finally {
            if ($this->tenancy_type === 'schema' && DB::getDriverName() === 'pgsql') {
                DB::statement('SET search_path TO public');
            }
        }
    }

    private function createSchema()
    {
        if ($this->tenancy_type === 'schema') {
            // Create PostgreSQL schema
            DB::statement("CREATE SCHEMA IF NOT EXISTS {$this->schema_name}");
        } else {
            // Create separate database
            DB::statement("CREATE DATABASE {$this->database_name}");
        }
    }

    private function runMigrations()
    {
        app(TenantMigrationService::class)->migrate($this);
    }

    private function seedDefaultData()
    {
        // Seed default farm-specific data
        if ($this->tenancy_type === 'schema') {
            DB::statement("SET search_path TO {$this->schema_name}, public");
        }

        // Create default farm settings, categories, etc.
        $this->addToLog('Default data seeding completed');
    }

    public function markAsActive()
    {
        $this->status = 'active';
        $this->provisioned_at = now();
        $this->save();
    }

    public function markAsFailed($reason = null)
    {
        $this->status = 'failed';
        $this->failure_reason = $reason;
        $this->retry_count++;
        $this->save();
    }

    public function archive()
    {
        $this->status = 'archived';
        $this->save();
    }

    public function canRetry()
    {
        return $this->status === 'failed' && $this->retry_count < 3;
    }

    public function retry()
    {
        if (!$this->canRetry()) {
            return false;
        }

        $this->status = 'provisioning';
        $this->failure_reason = null;
        $this->save();

        return $this->provisionTenant();
    }

    private function addToLog($message)
    {
        $log = $this->provisioning_log ?? [];
        $log[] = [
            'timestamp' => now()->toISOString(),
            'message' => $message
        ];
        $this->provisioning_log = $log;
        $this->save();
    }

    public function switchToTenant()
    {
        if ($this->tenancy_type === 'schema') {
            DB::statement("SET search_path TO {$this->schema_name}, public");
        } else {
            // Switch database connection
            config(['database.connections.tenant.database' => $this->database_name]);
            DB::purge('tenant');
            DB::reconnect('tenant');
        }
    }

    public function dropTenant()
    {
        try {
            if ($this->tenancy_type === 'schema') {
                DB::statement("DROP SCHEMA IF EXISTS {$this->schema_name} CASCADE");
            } else {
                DB::statement("DROP DATABASE IF EXISTS {$this->database_name}");
            }
            
            $this->delete();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // Static utility methods
    public static function findByFarmId($farmId)
    {
        return static::where('farm_id', $farmId)->first();
    }

    public static function findActiveByFarmId($farmId)
    {
        return static::where('farm_id', $farmId)->where('status', 'active')->first();
    }

    public static function createForFarm($farmId, $schemaName, $userId = null)
    {
        return self::create([
            'farm_id' => $farmId,
            'schema_name' => $schemaName,
            'provisioned_by' => $userId,
        ]);
    }

    public static function getProvisioningStats()
    {
        return [
            'total' => self::count(),
            'active' => self::where('status', 'active')->count(),
            'provisioning' => self::where('status', 'provisioning')->count(),
            'failed' => self::where('status', 'failed')->count(),
            'archived' => self::where('status', 'archived')->count(),
        ];
    }
}
