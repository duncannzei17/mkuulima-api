<?php

namespace App\Console\Commands;

use App\Services\ProductionReadinessService;
use Illuminate\Console\Command;

class ProductionReadiness extends Command
{
    protected $signature = 'system:production-readiness {--skip-connectivity : Validate configuration without contacting dependencies} {--json : Emit machine-readable output}';

    protected $description = 'Validate production configuration and required service connectivity';

    public function handle(ProductionReadinessService $readiness): int
    {
        $checks = $readiness->inspect(!$this->option('skip-connectivity'));
        $ready = $readiness->isReady($checks);

        if ($this->option('json')) {
            $this->line(json_encode(['ready' => $ready, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Check', 'Status', 'Detail'],
                collect($checks)->map(fn (array $check) => [$check['name'], strtoupper($check['status']), $check['message']])->all()
            );
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
