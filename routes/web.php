<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return response()->json([
        'message' => 'FarmOS API',
        'version' => '1.0.0',
        'status' => 'running'
    ]);
});

// Named login route required by Laravel's exception handler
Route::get('/login', function () {
    return response()->json([
        'message' => 'Authentication required',
        'login_url' => '/api/auth/login'
    ], 401);
})->name('login');