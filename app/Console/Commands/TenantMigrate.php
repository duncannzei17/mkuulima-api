<?php

namespace App\Console\Commands;

use App\Models\TenantRegistry;
use App\Services\TenantMigrationService;
use Illuminate\Console\Command;
use Throwable;

class TenantMigrate extends Command
{
    protected $signature = 'tenant:migrate
        {farm_id? : Migrate one farm; omit only with --all}
        {--all : Migrate every active tenant}
        {--baseline-existing : Record existing legacy tenant migrations after manual review}';

    protected $description = 'Run tracked Laravel migrations for tenant schemas or databases';

    public function handle(TenantMigrationService $migrations): int
    {
        $farmId = $this->argument('farm_id');

        if (!$farmId && !$this->option('all')) {
            $this->error('Provide a farm_id or explicitly pass --all.');

            return self::INVALID;
        }

        $tenants = TenantRegistry::query()
            ->active()
            ->when($farmId, fn ($query) => $query->where('farm_id', $farmId))
            ->get();

        if ($tenants->isEmpty()) {
            if (!$farmId && $this->option('all')) {
                $this->info('No active tenants to migrate.');

                return self::SUCCESS;
            }

            $this->error('No matching active tenants found.');

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($tenants as $tenant) {
            $this->components->task("Migrating {$tenant->schema_name}", function () use ($migrations, $tenant, &$failures): bool {
                try {
                    $migrations->migrate($tenant, (bool) $this->option('baseline-existing'));

                    return true;
                } catch (Throwable $exception) {
                    $failures++;
                    $tenant->forceFill(['failure_reason' => $exception->getMessage()])->save();
                    report($exception);
                    $this->newLine();
                    $this->error($exception->getMessage());

                    return false;
                }
            });
        }

        if ($failures > 0) {
            $this->error("{$failures} tenant migration(s) failed.");

            return self::FAILURE;
        }

        $this->info("Migrated {$tenants->count()} tenant(s).");

        return self::SUCCESS;
    }
}
