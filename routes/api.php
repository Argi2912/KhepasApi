<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\RequestTypeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api',
    'prefix' => 'permissions'
], function () {
    Route::get('/', [PermissionController::class, 'index']);
    Route::post('/', [PermissionController::class, 'createPermission']);
    Route::post('/role', [PermissionController::class, 'createRole']);
    Route::post('/role-permissions', [PermissionController::class, 'assignRolePermissions']);
    Route::post('/user-roles', [PermissionController::class, 'assignUserRoles']);
    Route::post('/user-permissions', [PermissionController::class, 'assignUserDirectPermissions']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::get('me', [AuthController::class, 'me']);
});

Route::group([
    'prefix' => 'register'
], function () {
    Route::post('register', [RegisterController::class, 'register']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'users'
], function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::put('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
    Route::patch('/{id}/status', [UserController::class, 'changeStatus']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'currencies'
], function () {
    Route::get('/', [CurrencyController::class, 'index']);
    Route::post('/', [CurrencyController::class, 'store']);
    Route::put('/{id}', [CurrencyController::class, 'update']);
    Route::delete('/{id}', [CurrencyController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'platforms'
], function () {
    Route::get('/', [PlatformController::class, 'index']);
    Route::post('/', [PlatformController::class, 'store']);
    Route::put('/{id}', [PlatformController::class, 'update']);
    Route::delete('/{id}', [PlatformController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'request-types'
], function () {
    Route::get('/', [RequestTypeController::class, 'index']);
    Route::post('/', [RequestTypeController::class, 'store']);
    Route::put('/{id}', [RequestTypeController::class, 'update']);
    Route::delete('/{id}', [RequestTypeController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'exchange-rates'
], function () {
    Route::get('/', [ExchangeRateController::class, 'index']);
    Route::post('/', [ExchangeRateController::class, 'store']);
    Route::put('/{id}', [ExchangeRateController::class, 'update']);
    Route::delete('/{id}', [ExchangeRateController::class, 'destroy']);
});