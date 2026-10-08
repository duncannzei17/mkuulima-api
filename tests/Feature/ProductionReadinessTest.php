<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    public function test_liveness_endpoint_does_not_depend_on_external_services(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['service', 'version', 'timestamp']);
    }

    public function test_readiness_endpoint_fails_closed_when_a_dependency_is_unavailable(): void
    {
        $this->getJson('/api/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonFragment(['name' => 'database', 'status' => 'failed'])
            ->assertJsonFragment(['name' => 'cache', 'status' => 'ok'])
            ->assertJsonFragment(['name' => 'queue', 'status' => 'ok'])
            ->assertJsonFragment(['name' => 'storage', 'status' => 'ok']);
    }

    public function test_production_readiness_command_fails_for_unsafe_configuration(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config([
            'app.debug' => true,
            'app.key' => null,
            'app.url' => 'http://localhost',
            'cors.allowed_origins' => ['*'],
            'queue.default' => 'sync',
            'cache.default' => 'array',
            'session.secure' => false,
        ]);

        $exitCode = Artisan::call('system:production-readiness', [
            '--skip-connectivity' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('debug_disabled', $output);
        $this->assertStringContainsString('cors_origins', $output);
    }

    public function test_sensitive_auth_routes_have_dedicated_rate_limits(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $login = $routes->first(fn ($route) => $route->uri() === 'api/auth/login');
        $refresh = $routes->first(fn ($route) => $route->uri() === 'api/auth/refresh');

        $this->assertNotNull($login);
        $this->assertContains('throttle:auth', $login->middleware());
        $this->assertNotNull($refresh);
        $this->assertContains('throttle:token-refresh', $refresh->middleware());
    }

    public function test_cors_uses_explicit_origins_with_credentials(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertTrue(config('cors.supports_credentials'));
        $this->assertContains('Retry-After', config('cors.exposed_headers'));
        $this->assertContains('X-RateLimit-Limit', config('cors.exposed_headers'));
        $this->assertContains('X-RateLimit-Remaining', config('cors.exposed_headers'));
    }

    public function test_api_limiter_uses_separate_authenticated_and_guest_budgets(): void
    {
        config([
            'rate_limiting.api.authenticated_per_minute' => 300,
            'rate_limiting.api.guest_per_minute' => 120,
        ]);

        $user = \Mockery::mock();
        $user->shouldReceive('getAuthIdentifier')->once()->andReturn('user-123');
        $guard = \Mockery::mock();
        $guard->shouldReceive('user')->twice()->andReturn(null, $user);
        \Illuminate\Support\Facades\Auth::shouldReceive('guard')
            ->twice()
            ->with('sanctum')
            ->andReturn($guard);

        $limiter = RateLimiter::limiter('api');
        $guestRequest = Request::create('/api/health', 'GET');
        $guestRequest->headers->set('X-Tenant-ID', 'tenant-a');
        $guestLimit = $limiter($guestRequest);
        $authenticatedLimit = $limiter($guestRequest);

        $this->assertSame(120, $guestLimit->maxAttempts);
        $this->assertSame(300, $authenticatedLimit->maxAttempts);
        $this->assertNotSame($guestLimit->key, $authenticatedLimit->key);
        $this->assertStringStartsWith('api:v2:', $guestLimit->key);
        $this->assertIsCallable($guestLimit->responseCallback);

        $response = ($guestLimit->responseCallback)($guestRequest, ['Retry-After' => 17]);
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('17', $response->headers->get('Retry-After'));
        $this->assertSame(17, $response->getData(true)['retry_after']);
    }

    public function test_api_throttle_returns_machine_readable_retry_metadata(): void
    {
        config(['rate_limiting.api.guest_per_minute' => 2]);

        $ip = '198.51.100.27';
        $key = 'api:v2:'.hash('sha256', 'ip:'.$ip.'|no-tenant');
        RateLimiter::clear($key);

        $request = fn () => $this
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeader('Origin', config('cors.allowed_origins')[0])
            ->getJson('/api/health/live');

        $request()->assertOk();
        $request()->assertOk();
        $request()
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertHeader('Access-Control-Expose-Headers')
            ->assertJsonPath('message', 'Too many requests. Please try again later.')
            ->assertJsonPath('retry_after', 60);

        RateLimiter::clear($key);
    }
}
