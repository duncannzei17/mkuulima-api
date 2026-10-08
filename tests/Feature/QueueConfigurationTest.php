<?php

namespace Tests\Feature;

use App\Jobs\SyncWeatherData;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    public function test_database_queue_contract_and_weather_job_serialization(): void
    {
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertSame('jobs', config('queue.connections.database.table'));
        $this->assertTrue(config('queue.connections.database.after_commit'));
        $this->assertSame('failed_jobs', config('queue.failed.table'));

        $payload = serialize(new SyncWeatherData('00000000-0000-4000-8000-000000000001'));

        $this->assertStringContainsString(SyncWeatherData::class, $payload);
        $this->assertStringContainsString('00000000-0000-4000-8000-000000000001', $payload);
    }
}
