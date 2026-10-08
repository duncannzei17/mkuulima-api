<?php

namespace App\Services;

use App\Models\TenantRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class TenantMigrationService
{
    public function migrate(TenantRegistry $tenant, bool $baselineExisting = false): void
    {
        $originalDefault = config('database.default');
        $connection = $tenant->tenancy_type === 'database' ? 'tenant' : $originalDefault;

        try {
            $tenant->switchToTenant();
            config(['database.default' => $connection]);

            $this->prepareMigrationRepository($connection, $baselineExisting);

            $exitCode = Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(trim(Artisan::output()) ?: 'Tenant migration command failed.');
            }
        } finally {
            config(['database.default' => $originalDefault]);

            if (DB::connection($originalDefault)->getDriverName() === 'pgsql') {
                DB::connection($originalDefault)->statement('SET search_path TO public');
            }
        }

        $tenant->forceFill([
            'last_migration_at' => now(),
            'migration_version' => $this->latestMigrationVersion(),
            'failure_reason' => null,
        ])->save();
    }

    private function prepareMigrationRepository(string $connection, bool $baselineExisting): void
    {
        $schema = Schema::connection($connection);
        $hasMigrationRepository = $schema->hasTable('migrations');

        $hasExistingTenantData = collect($this->migrationFiles())->contains(function (string $file) use ($schema): bool {
            $source = $this->migrationSource($file);

            return preg_match("/Schema::create\(['\"]([^'\"]+)/", $source, $matches) === 1
                && $schema->hasTable($matches[1]);
        });

        if (! $hasMigrationRepository && $hasExistingTenantData && ! $baselineExisting) {
            throw new RuntimeException(
                'This legacy tenant has tables but no migration repository. Review it and rerun with --baseline-existing.'
            );
        }

        if (! $hasMigrationRepository) {
            $schema->create('migrations', function ($table): void {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
        }

        if ($hasExistingTenantData && $baselineExisting) {
            $existingMigrations = DB::connection($connection)->table('migrations')->pluck('migration');
            $rows = collect($this->baselineMigrationNames(
                $this->migrationFiles(),
                fn (string $table): bool => $schema->hasTable($table)
            ))
                ->diff($existingMigrations)
                ->map(fn (string $migration) => [
                    'migration' => $migration,
                    'batch' => 1,
                ])->values()->all();

            if ($rows !== []) {
                DB::connection($connection)->table('migrations')->insert($rows);
            }
        }
    }

    /**
     * Baseline only create-table migrations whose complete table set already exists.
     * Alteration and data migrations must remain pending so legacy schemas are upgraded.
     *
     * @param  array<int, string>  $files
     * @return array<int, string>
     */
    private function baselineMigrationNames(array $files, callable $tableExists): array
    {
        return collect($files)
            ->filter(function (string $file) use ($tableExists): bool {
                $source = $this->migrationSource($file);
                preg_match_all("/Schema::create\(['\"]([^'\"]+)/", $source, $matches);
                $tables = array_values(array_unique($matches[1] ?? []));

                return $tables !== [] && collect($tables)->every($tableExists);
            })
            ->map(fn (string $file): string => pathinfo($file, PATHINFO_FILENAME))
            ->values()
            ->all();
    }

    private function migrationSource(string $file): string
    {
        $source = file_get_contents($file) ?: '';
        if (! preg_match("/return\s+require\s+database_path\(['\"]([^'\"]+)/", $source, $matches)) {
            return $source;
        }

        $databaseRoot = realpath(database_path());
        $requiredFile = realpath(database_path($matches[1]));
        if ($databaseRoot === false || $requiredFile === false
            || ! str_starts_with($requiredFile, $databaseRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Tenant migration alias resolves outside the database directory: {$file}");
        }

        return file_get_contents($requiredFile) ?: '';
    }

    /** @return array<int, string> */
    private function migrationFiles(): array
    {
        $files = glob(database_path('migrations/tenant/*.php')) ?: [];
        sort($files);

        return $files;
    }

    private function latestMigrationVersion(): string
    {
        $latest = collect($this->migrationFiles())->last();

        return $latest ? pathinfo($latest, PATHINFO_FILENAME) : 'none';
    }
}
