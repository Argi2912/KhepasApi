<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrenciesController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\RequestTypeController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\CorredorController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SolicitudController;
use App\Http\Controllers\DatabaseReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => 'api',
    'prefix' => 'permissions'
], function () {
    Route::get('/', [PermissionController::class, 'index']);
    Route::post('/', [PermissionController::class, 'createPermission']);
    Route::post('/role', [PermissionController::class, 'createRole']);
    Route::put('/role/{id}', [PermissionController::class, 'updateRole']);
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
    Route::get('/', [CurrenciesController::class, 'index']);
    Route::post('/', [CurrenciesController::class, 'store']);
    Route::put('/{id}', [CurrenciesController::class, 'update']);
    Route::delete('/{id}', [CurrenciesController::class, 'destroy']);
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
Route::group([
    'middleware' => 'api',
    'prefix' => 'solicitudes'
], function () {
    Route::get('/', [SolicitudController::class, 'index']);
    Route::post('/', [SolicitudController::class, 'store']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'clients'
], function () {
    Route::get('/', [ClientController::class, 'index']);
    Route::post('/', [ClientController::class, 'store']);
    Route::put('/{id}', [ClientController::class, 'update']);
    Route::delete('/{id}', [ClientController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'providers'
], function () {
    Route::get('/', [ProviderController::class, 'index']);
    Route::post('/', [ProviderController::class, 'store']);
    Route::put('/{id}', [ProviderController::class, 'update']);
    Route::delete('/{id}', [ProviderController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'corredores'
], function () {
    Route::get('/', [CorredorController::class, 'index']);
    Route::post('/', [CorredorController::class, 'store']);
    Route::put('/{id}', [CorredorController::class, 'update']);
    Route::delete('/{id}', [CorredorController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'admins'
], function () {
    Route::get('/', [AdminController::class, 'index']);
    Route::post('/', [AdminController::class, 'store']);
    Route::put('/{id}', [AdminController::class, 'update']);
    Route::delete('/{id}', [AdminController::class, 'destroy']);
});
Route::group([
    'middleware' =>['auth:api'],
    
],
function () {

    Route::get('/reports/clients', [DatabaseReportController::class, 'getClients'])
         ->middleware('permission:view client database');

    Route::get('/reports/providers', [DatabaseReportController::class, 'getProviders'])
         ->middleware('permission:view provider database');

    Route::get('/reports/brokers', [DatabaseReportController::class, 'getBrokers'])
         ->middleware('permission:view broker database');

    Route::get('/reports/admins', [DatabaseReportController::class, 'getAdmins'])
         ->middleware('permission:view admin database');

    Route::get('/reports/requests', [DatabaseReportController::class, 'getRequests'])
         ->middleware('permission:view request history');
});