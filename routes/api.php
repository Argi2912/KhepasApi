<?php

use App\Http\Controllers\Api\AccountController;

/*
|--------------------------------------------------------------------------
| Importación de Controladores
|--------------------------------------------------------------------------
*/
// Autenticación y Globales
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrokerController;

// Tableros y Reportes
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CurrencyExchangeController;

// Catálogos (Bases de Datos)
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\InternalTransactionController;
use App\Http\Controllers\Api\InvestorController;
use App\Http\Controllers\Api\LedgerEntryController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ProviderController;

// Operaciones Financieras (El Núcleo)
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\Superadmin\ActivityLogController;
use App\Http\Controllers\Api\Superadmin\TenantController;
use App\Http\Controllers\Api\TenantUserController;
use App\Http\Controllers\Api\TransactionRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 1. RUTAS PÚBLICAS
|--------------------------------------------------------------------------
*/
Route::post('login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| 2. RUTAS DE SUPERADMIN
|--------------------------------------------------------------------------
*/
Route::group(['middleware' => ['auth:api', 'role:superadmin'], 'prefix' => 'superadmin'], function () {
    Route::get('/stats', [TenantController::class, 'dashboardStats']);
    Route::apiResource('tenants', TenantController::class);
    Route::patch('/tenants/{tenant}/toggle', [TenantController::class, 'toggleStatus']);
    //Route::post('tenants/{tenant}/users', [SuperadminTenantUserController::class, 'store']);

    Route::get('/logs', [ActivityLogController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| 3. RUTAS DEL TENANT (SISTEMA OPERATIVO)
|--------------------------------------------------------------------------
*/
Route::group(['middleware' => ['auth:api']], function () {

    // --- A. Autenticación y Perfil ---
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    // --- B. Dashboard y Métricas ---
    Route::get('dashboard/summary', [DashboardController::class, 'getSummary'])
        ->middleware('permission:view_dashboard');

    Route::get('statistics/performance', [StatisticsController::class, 'getPerformance'])
        ->middleware('permission:view_statistics');

    Route::get('statistics/providers', [StatisticsController::class, 'getProviderReport'])
        ->middleware('permission:view_statistics');

    Route::get('/reports/profit-matrix', [App\Http\Controllers\Api\ReportController::class, 'profitMatrix']);

// Opcional: Agrega las rutas para el resto de los reportes para ser consistentes
    Route::get('statistics/clients', [StatisticsController::class, 'getClientReport'])
        ->middleware('permission:view_statistics');

    Route::get('statistics/platforms', [StatisticsController::class, 'getPlatformReport'])
        ->middleware('permission:view_statistics');

    Route::get('statistics/brokers', [StatisticsController::class, 'getBrokerReport'])
        ->middleware('permission:view_statistics');

    // --- C. Catálogos y Recursos ---
    Route::apiResource('clients', ClientController::class)
        ->middleware('permission:manage_clients');

    Route::apiResource('providers', ProviderController::class)
        ->middleware('permission:manage_clients');

    Route::apiResource('brokers', BrokerController::class)
        ->middleware('permission:manage_users');

    Route::apiResource('accounts', AccountController::class)
        ->middleware('permission:manage_exchanges');

    Route::apiResource('platforms', PlatformController::class)
        ->middleware('permission:manage_platforms');

    Route::apiResource('currencies', CurrencyController::class)
        ->middleware('permission:manage_exchanges');

    // --- D. MÓDULOS FINANCIEROS ---

    // 1. Solicitudes (Buzón)
    Route::apiResource('transactions/requests', TransactionRequestController::class)
        ->only(['index', 'store', 'update'])
        ->middleware('permission:manage_transaction_requests');
    Route::patch('/transactions/exchanges/{exchange}/deliver', [CurrencyExchangeController::class, 'markDelivered']);

    // 2. Transacciones Internas (Caja)
    Route::apiResource('transactions/internal', InternalTransactionController::class)
        ->only(['index', 'store', 'show'])
        ->middleware('permission:manage_internal_transactions');

    // 3. Intercambios de Divisa (Motor Unificado)
    Route::apiResource('transactions/exchanges', CurrencyExchangeController::class)
        ->only(['index', 'show', 'store'])
        ->middleware('permission:manage_exchanges');

    // --- E. Lógica de Negocio (Tasas) ---

    // Ruta ligera para obtener todas las tasas en el Frontend (Store)
    Route::get('rates/all', [ExchangeRateController::class, 'all'])
        ->middleware('permission:manage_exchanges');

    // CRUD de Tasas (Configuración)
    Route::apiResource('rates', ExchangeRateController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->middleware('permission:manage_exchanges');

    // --- F. Contabilidad y Auditoría (CORREGIDO Y ORDENADO) ---

    // 1. Resumen de Cuentas (Tarjetas Rojas/Verdes) - IMPORTANTE: Debe ir antes del resource
    Route::get('ledger/summary', [LedgerEntryController::class, 'summary']);

    // 2. Pagar Deuda
    Route::post('ledger/{ledger_entry}/pay', [LedgerEntryController::class, 'pay']);

    // 3. Listado General
    Route::apiResource('ledger', LedgerEntryController::class)
        ->only(['index', 'store', 'update']);

    // 4. Historial
    Route::get('audit-logs', [AuditLogController::class, 'index'])
        ->middleware('permission:view_database_history');

    Route::apiResource('investors', InvestorController::class);

    // --- G. Gestión de Usuarios ---
    Route::get('users/available-roles', [TenantUserController::class, 'getAvailableRoles'])
        ->middleware('permission:manage_users');

    Route::apiResource('users', TenantUserController::class)
        ->middleware('permission:manage_users');

});
