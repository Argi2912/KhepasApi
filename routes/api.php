<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuditLogController;

/*
|--------------------------------------------------------------------------
| Controladores de Autenticación y Superadmin
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrokerController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CurrencyController;
/*
|--------------------------------------------------------------------------
| Controladores del Tenant (Cartera y Cuentas)
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\CurrencyExchangeController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DollarPurchaseController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\LedgerEntryController;
/*
|--------------------------------------------------------------------------
| Controladores del Tenant (Lógica de Negocio y Transacciones)
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\Superadmin\TenantController;
use App\Http\Controllers\Api\Superadmin\TenantUserController as SuperadminTenantUserController;

/*
|--------------------------------------------------------------------------
| Controladores del Tenant (Dashboards y Auditoría)
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\TenantUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 1. RUTAS PÚBLICAS
|--------------------------------------------------------------------------
|
| Rutas para autenticación.
|
*/
Route::post('login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| 2. RUTAS DE SUPERADMIN
|--------------------------------------------------------------------------
|
| Rutas protegidas para el rol 'superadmin'.
| Estas rutas NO están filtradas por el TenantScope.
|
*/
Route::group(['middleware' => ['auth:api', 'role:superadmin'], 'prefix' => 'superadmin'], function () {
    // Gestiona los Tenants (CRUD completo)
    Route::apiResource('tenants', TenantController::class);

                                                                                             // Crea el primer usuario (admin) para un Tenant
    Route::post('tenants/{tenant}/users', [SuperadminTenantUserController::class, 'store']); // 🚨 USAMOS EL ALIAS
});

/*
|--------------------------------------------------------------------------
| 3. RUTAS DEL TENANT (AUTENTICADAS)
|--------------------------------------------------------------------------
|
| Rutas protegidas por 'auth:api'. El TenantScope se aplica
| automáticamente a todos los modelos que usan el trait BelongsToTenant.
|
*/
Route::group(['middleware' => ['auth:api']], function () {

    // --- Autenticación y Perfil ---
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    // --- Módulo 1: Home (Dashboard) ---
    Route::get('dashboard/summary', [DashboardController::class, 'getSummary'])
        ->middleware('permission:view_dashboard');

    // --- Módulo 5: Estadísticas ---
    Route::get('statistics/performance', [StatisticsController::class, 'getPerformance'])
        ->middleware('permission:view_statistics');

    // --- Módulo 4: Bases de Datos (Cartera y Cuentas) ---
    // (Estas rutas son usadas por el Módulo 3 para los <select>)
    Route::apiResource('clients', ClientController::class);
    Route::apiResource('providers', ProviderController::class);
    Route::apiResource('brokers', BrokerController::class);
    Route::apiResource('accounts', AccountController::class);

    // --- Módulo 3: Solicitudes (Transacciones) ---
    Route::apiResource('transactions/currency-exchange', CurrencyExchangeController::class)
        ->only(['index', 'show', 'store'])
        ->middleware('permission:manage_requests'); // 'index' y 'show' podrían tener 'view_database_history'

    Route::apiResource('transactions/dollar-purchase', DollarPurchaseController::class)
        ->only(['index', 'show', 'store'])
        ->middleware('permission:manage_requests');

    // --- Lógica de Negocio (Tasas) ---
    Route::apiResource('rates', ExchangeRateController::class)
        ->only(['index', 'show', 'store'])
        ->middleware('permission:manage_rates');

    Route::apiResource('currencies', CurrencyController::class)
        ->middleware('permission:manage_rates');

    // --- Contabilidad (Por Pagar / Por Cobrar) ---
    Route::apiResource('ledger', LedgerEntryController::class)
        ->only(['index', 'show', 'update']); // Solo listar, ver y actualizar (ej. pagar)

    // --- Auditoría ---
    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:view_database_history');

    Route::get('users/available-roles', [TenantUserController::class, 'getAvailableRoles'])
        ->middleware('permission:manage_users');
                                                             // --- Gestión de Usuarios (del Tenant) ---
    Route::apiResource('users', TenantUserController::class) // 🚨 USAMOS EL CONTROLADOR CORRECTO
        ->middleware('permission:manage_users');
});
