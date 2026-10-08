<?php

namespace App\Http\Middleware;

use App\Models\TenantRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SwitchTenantByFarmHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SET search_path TO public');
        }

        $farmId = $request->header('X-Tenant-ID') ?? $request->input('farm_id');

        if ($farmId) {
            $user = Auth::guard('sanctum')->user();
            abort_unless($user && $user->hasAccessToFarm($farmId), 403, 'You do not have access to this farm.');

            $tenant = TenantRegistry::where('farm_id', $farmId)
                ->where('status', 'active')
                ->first();

            if ($tenant) {
                $tenant->switchToTenant();
            }
        }

        try {
            return $next($request);
        } finally {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET search_path TO public');
            }
        }
    }
}
