<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FrameworkBootTest extends TestCase
{
    public function test_health_endpoint_boots_on_laravel_twelve(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('service', 'farmOS API')
            ->assertJsonStructure(['version', 'timestamp']);

        $this->assertStringStartsWith('12.', app()->version());
    }

    public function test_critical_expense_routes_remain_registered(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $this->assertTrue($routes->contains(
            fn ($route) => $route->uri() === 'api/expenses'
                && in_array('GET', $route->methods(), true)
                && in_array('auth:sanctum', $route->middleware(), true)
        ));
        $this->assertTrue($routes->contains(
            fn ($route) => $route->uri() === 'api/expenses/{expenseId}'
                && in_array('PUT', $route->methods(), true)
                && in_array('auth:sanctum', $route->middleware(), true)
        ));
        $this->assertTrue($routes->contains(
            fn ($route) => $route->uri() === 'api/expenses/approvals'
                && in_array('POST', $route->methods(), true)
                && in_array('auth:sanctum', $route->middleware(), true)
        ));
    }
}
