<?php

namespace Tests\Unit;

use App\Services\TenantMigrationService;
use ReflectionMethod;
use Tests\TestCase;

class TenantMigrationServiceTest extends TestCase
{
    public function test_legacy_baseline_records_existing_create_migrations_but_leaves_upgrades_pending(): void
    {
        $directory = sys_get_temp_dir().'/farmos-baseline-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $existing = $directory.'/2024_01_01_000001_create_sales.php';
        $missing = $directory.'/2024_01_01_000002_create_payments.php';
        $upgrade = $directory.'/2026_09_14_000001_repair_sales.php';
        file_put_contents($existing, "<?php Schema::create('sales', function () {});");
        file_put_contents($missing, "<?php Schema::create('sale_payments', function () {});");
        file_put_contents($upgrade, "<?php Schema::table('sales', function () {});");

        try {
            $method = new ReflectionMethod(TenantMigrationService::class, 'baselineMigrationNames');
            $method->setAccessible(true);
            $aliasedMigration = database_path('migrations/tenant/2024_04_01_000001_create_expense_categories_table.php');
            $result = $method->invoke(
                new TenantMigrationService,
                [$existing, $missing, $upgrade, $aliasedMigration],
                fn (string $table): bool => in_array($table, ['sales', 'expense_categories'], true)
            );

            $this->assertSame([
                '2024_01_01_000001_create_sales',
                '2024_04_01_000001_create_expense_categories_table',
            ], $result);
        } finally {
            @unlink($existing);
            @unlink($missing);
            @unlink($upgrade);
            @rmdir($directory);
        }
    }
}
