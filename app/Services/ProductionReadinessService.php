<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProductionReadinessService
{
    /**
     * @return array<int, array{name: string, status: string, message: string}>
     */
    public function inspect(bool $includeConnectivity = true): array
    {
        $checks = [];

        if (app()->environment('production')) {
            $checks = array_merge($checks, $this->productionConfigurationChecks());
        }

        if ($includeConnectivity) {
            $checks = array_merge($checks, [
                $this->check('database', fn () => DB::connection()->select('SELECT 1')),
                $this->cacheCheck(),
                $this->queueCheck(),
                $this->writablePathCheck('storage', storage_path()),
            ]);
        }

        return $checks;
    }

    /** @param array<int, array{status: string}> $checks */
    public function isReady(array $checks): bool
    {
        return collect($checks)->every(fn (array $check) => $check['status'] === 'ok');
    }

    /** @return array<int, array{name: string, status: string, message: string}> */
    private function productionConfigurationChecks(): array
    {
        $origins = config('cors.allowed_origins', []);
        $queue = config('queue.default');
        $cache = config('cache.default');
        $database = config('database.default');
        $usesRedis = in_array('redis', [$queue, $cache, config('session.driver')], true);

        return [
            $this->configurationCheck('debug_disabled', !config('app.debug'), 'APP_DEBUG must be false.'),
            $this->configurationCheck('application_key', filled(config('app.key')), 'APP_KEY must be configured.'),
            $this->configurationCheck('database_password', filled(config("database.connections.{$database}.password")), 'The production database password must be configured.'),
            $this->configurationCheck('redis_password', !$usesRedis || filled(config('database.redis.default.password')), 'REDIS_PASSWORD must be configured when Redis is used.'),
            $this->configurationCheck('https_url', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL must use HTTPS.'),
            $this->configurationCheck(
                'cors_origins',
                is_array($origins)
                    && $origins !== []
                    && !in_array('*', $origins, true)
                    && collect($origins)->every(fn ($origin) => !str_contains((string) $origin, 'localhost')),
                'CORS_ALLOWED_ORIGINS must contain explicit non-local production origins.'
            ),
            $this->configurationCheck('queue_driver', !in_array($queue, ['sync', 'null'], true), 'QUEUE_CONNECTION must be asynchronous.'),
            $this->configurationCheck('cache_driver', !in_array($cache, ['array', 'null', 'file'], true), 'CACHE_STORE must be shared across application instances.'),
            $this->configurationCheck('secure_session_cookie', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE must be true.'),
            $this->configurationCheck('log_level', config('logging.channels.'.config('logging.default').'.level', config('logging.level')) !== 'debug', 'LOG_LEVEL must not be debug.'),
        ];
    }

    /** @return array{name: string, status: string, message: string} */
    private function cacheCheck(): array
    {
        return $this->check('cache', function (): void {
            $key = 'readiness:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 10);

            if (Cache::get($key) !== 'ok') {
                throw new \RuntimeException('Cache round trip failed.');
            }

            Cache::forget($key);
        });
    }

    /** @return array{name: string, status: string, message: string} */
    private function queueCheck(): array
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            return $this->configurationCheck('queue', true, 'Inline queue is available.');
        }

        if ($connection === 'database') {
            return $this->check('queue', function (): void {
                if (!Schema::hasTable(config('queue.connections.database.table', 'jobs'))) {
                    throw new \RuntimeException('Queue jobs table is missing.');
                }
            });
        }

        if ($connection === 'redis') {
            return $this->check('queue', function (): void {
                $redisConnection = config('queue.connections.redis.connection', 'default');
                app('redis')->connection($redisConnection)->ping();
            });
        }

        return $this->configurationCheck('queue', false, "Unsupported queue connection [{$connection}].");
    }

    /** @return array{name: string, status: string, message: string} */
    private function writablePathCheck(string $name, string $path): array
    {
        return $this->configurationCheck($name, is_dir($path) && is_writable($path), "{$path} must be writable.");
    }

    /** @return array{name: string, status: string, message: string} */
    private function check(string $name, callable $check): array
    {
        try {
            $check();

            return ['name' => $name, 'status' => 'ok', 'message' => 'Available.'];
        } catch (Throwable $exception) {
            report($exception);

            return ['name' => $name, 'status' => 'failed', 'message' => $exception->getMessage()];
        }
    }

    /** @return array{name: string, status: string, message: string} */
    private function configurationCheck(string $name, bool $passes, string $failureMessage): array
    {
        return [
            'name' => $name,
            'status' => $passes ? 'ok' : 'failed',
            'message' => $passes ? 'Configured.' : $failureMessage,
        ];
    }
}
