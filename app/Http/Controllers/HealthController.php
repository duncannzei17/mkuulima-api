<?php

namespace App\Http\Controllers;

use App\Services\ProductionReadinessService;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'farmOS API',
            'version' => '1.0.0',
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function ready(ProductionReadinessService $readiness): JsonResponse
    {
        $checks = $readiness->inspect();
        $ready = $readiness->isReady($checks);

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'service' => 'farmOS API',
            'checks' => collect($checks)
                ->map(fn (array $check) => collect($check)->only(['name', 'status'])->all())
                ->values(),
            'timestamp' => now()->toISOString(),
        ], $ready ? 200 : 503);
    }
}
