<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $user = Auth::guard('sanctum')->user();
            $tenant = (string) $request->header('X-Tenant-ID', 'no-tenant');
            $identity = $user
                ? 'user:'.$user->getAuthIdentifier()
                : 'ip:'.$request->ip();
            $limit = $user
                ? (int) config('rate_limiting.api.authenticated_per_minute', 300)
                : (int) config('rate_limiting.api.guest_per_minute', 120);

            return Limit::perMinute($limit)
                ->by('api:v2:'.hash('sha256', $identity.'|'.$tenant))
                ->response(function (Request $request, array $headers) {
                    $retryAfter = (int) ($headers['Retry-After'] ?? 60);

                    return response()->json([
                        'message' => 'Too many requests. Please try again later.',
                        'retry_after' => $retryAfter,
                    ], 429, $headers);
                });
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute((int) config('rate_limiting.auth_per_minute', 10))
                ->by($request->ip());
        });

        RateLimiter::for('token-refresh', function (Request $request) {
            return Limit::perMinute((int) config('rate_limiting.token_refresh_per_minute', 30))
                ->by($request->ip());
        });
    }
}
