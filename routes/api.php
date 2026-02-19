<?php

use App\Http\Controllers\Api\AccountController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SubscriptionController;

/*
|--------------------------------------------------------------------------
| Importación de Controladores
|--------------------------------------------------------------------------
*/

// Autenticación y Globales
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrokerController;
use App\Http\Controllers\Api\RegisterController;
use App\Http\Controllers\Api\WebhookController;

// Tableros y Reportes
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\CurrencyExchangeController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StatisticsController;

// Catálogos y Operaciones
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\InternalTransactionController;
use App\Http\Controllers\Api\InvestorController;
use App\Http\Controllers\Api\LedgerEntryController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\DollarPurchaseController;
use App\Http\Controllers\Api\TransactionRequestController;
use App\Http\Controllers\Api\DailyClosingController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\TransactionController;

// Superadmin
use App\Http\Controllers\Api\Superadmin\ActivityLogController;
use App\Http\Controllers\Api\Superadmin\SuperadminUserController;
use App\Http\Controllers\Api\Superadmin\TenantController;
use App\Http\Controllers\Api\TenantUserController;
use App\Http\Controllers\Api\SupportController;

/*
|--------------------------------------------------------------------------
| 1. RUTAS PÚBLICAS
|--------------------------------------------------------------------------
*/

Route::post('login', [AuthController::class, 'login']);
Route::post('/register', [RegisterController::class, 'register']);
Route::post('/support/contact', [SupportController::class, 'sendContact']); // 👈 Ponla aquí

Route::get('tenants/check-status/{tenant}', function ($id) {
    $tenant = \App\Models\Tenant::find($id);
    return response()->json(['is_active' => $tenant->is_active]);
});

Route::post('/webhooks/stripe', [WebhookController::class, 'handleStripe']);
Route::post('/webhooks/paypal', [WebhookController::class, 'handlePayPal']);


/*
|--------------------------------------------------------------------------
| 2. RUTAS DE SUPERADMIN
|--------------------------------------------------------------------------
*/
Route::group(['middleware' => ['auth:api', 'role:superadmin'], 'prefix' => 'superadmin'], function () {
    // Perfil del Super Admin
    Route::get('/profile', [SuperadminUserController::class, 'profile']);
    Route::put('/profile', [SuperadminUserController::class, 'updateProfile']);

    // Gestión global de usuarios
    Route::get('/users', [SuperadminUserController::class, 'index']);
    Route::put('/users/{user}', [SuperadminUserController::class, 'update']);
    Route::post('/users/{user}/reset-password', [SuperadminUserController::class, 'resetPassword']);

    // Tenants
    Route::get('/stats', [TenantController::class, 'dashboardStats']);
    Route::apiResource('tenants', TenantController::class);
    Route::patch('/tenants/{tenant}/toggle', [TenantController::class, 'toggleStatus']);
    Route::get('/logs', [ActivityLogController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| 3. RUTAS DE USUARIO (LIBRES DE BLOQUEO)
|--------------------------------------------------------------------------
| Estas rutas DEBEN estar fuera del middleware 'tenant.active' para que
| el usuario pueda consultar sus datos y hacer logout aunque deba dinero.
*/
Route::group(['middleware' => ['auth:api']], function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    // --- RUTAS DE PAGO DE SUSCRIPCIÓN (NUEVO) ---
    Route::post('/subscription/paypal', [SubscriptionController::class, 'payWithPaypal']);
    Route::post('/subscription/capture-registration', [SubscriptionController::class, 'captureRegistrationPayment']);
    Route::post('/support/contact', [App\Http\Controllers\Api\SupportController::class, 'sendContact']);
});


/*
|--------------------------------------------------------------------------
| 4. RUTAS DEL TENANT (PROTEGIDAS POR PAGO)
|--------------------------------------------------------------------------
| Si la empresa está inactiva, todo lo de abajo dará error 402.
*/
Route::group(['middleware' => ['auth:api', 'tenant.active']], function () {

    // Ruta para el Daily Closing
    Route::get('/daily-closing', [DailyClosingController::class, 'index']);

    // --- B. Dashboard y Métricas ---
    Route::get('dashboard/summary', [DashboardController::class, 'getSummary'])
        ->middleware('permission:view_dashboard');

    Route::get('statistics/performance', [StatisticsController::class, 'getPerformance'])
        ->middleware('permission:view_statistics');

    Route::get('statistics/providers', [StatisticsController::class, 'getProviderReport'])
        ->middleware('permission:view_statistics');

    // REPORTES
    Route::get('/reports/data', [ReportController::class, 'getReportData']);
    Route::get('/reports/download', [ReportController::class, 'download']);
    Route::get('/reports/profit-matrix', [ReportController::class, 'profitMatrix']);
    Route::get('/reports/receipt/{id}', [ReportController::class, 'downloadTransactionReceipt']);

    Route::get('statistics/clients', [StatisticsController::class, 'getClientReport'])->middleware('permission:view_statistics');
    Route::get('statistics/platforms', [StatisticsController::class, 'getPlatformReport'])->middleware('permission:view_statistics');
    Route::get('statistics/brokers', [StatisticsController::class, 'getBrokerReport'])->middleware('permission:view_statistics');
    Route::get('statistics/investors', [StatisticsController::class, 'getInvestorReport'])->middleware('permission:view_statistics');

    // Empleados
    Route::apiResource('employees', EmployeeController::class);
    Route::post('/employees/process-payroll', [EmployeeController::class, 'processPayroll']);

    // --- C. Catálogos y Recursos ---
    Route::apiResource('clients', ClientController::class)->middleware('permission:manage_clients');
    Route::post('providers/{provider}/balance', [ProviderController::class, 'addBalance'])->middleware('permission:manage_clients');
    Route::apiResource('providers', ProviderController::class)->middleware('permission:manage_clients');
    Route::apiResource('brokers', BrokerController::class)->middleware('permission:manage_users');
    Route::apiResource('accounts', AccountController::class)->middleware('permission:manage_exchanges');
    Route::apiResource('platforms', PlatformController::class)->middleware('permission:manage_platforms');
    Route::apiResource('currencies', CurrencyController::class)->middleware('permission:manage_exchanges');

    // --- D. MÓDULOS FINANCIEROS ---
    Route::apiResource('transactions/requests', TransactionRequestController::class)
        ->only(['index', 'store', 'update'])
        ->middleware('permission:manage_transaction_requests');
    Route::patch('/transactions/exchanges/{exchange}/deliver', [CurrencyExchangeController::class, 'markDelivered']);

    Route::apiResource('transactions/internal', InternalTransactionController::class)
        ->only(['index', 'store', 'show'])
        ->middleware('permission:manage_internal_transactions');

    Route::apiResource('transactions/exchanges', CurrencyExchangeController::class)
        ->only(['index', 'show', 'store'])
        ->middleware('permission:manage_exchanges');

    // --- E. Lógica de Negocio (Tasas) ---
    Route::get('rates/all', [ExchangeRateController::class, 'all'])->middleware('permission:manage_exchanges');
    Route::apiResource('dollar-purchases', DollarPurchaseController::class)->middleware('permission:manage_dollar_purchases');
    Route::apiResource('rates', ExchangeRateController::class)
        ->only(['index', 'show', 'store', 'update'])
        ->middleware('permission:manage_exchanges');

    // --- F. Contabilidad y Auditoría ---
    Route::get('ledger/summary', [LedgerEntryController::class, 'summary']);
    Route::get('ledger/grouped', [LedgerEntryController::class, 'groupedPayables']);
    Route::post('ledger/{ledger_entry}/pay', [LedgerEntryController::class, 'pay']);
    Route::apiResource('ledger', LedgerEntryController::class)->only(['index', 'store', 'update']);

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:view_database_history');

    Route::post('investors/{investor}/balance', [InvestorController::class, 'addBalance']);
    Route::apiResource('investors', InvestorController::class);

    Route::post('/transactions/add-balance', [TransactionController::class, 'addBalance']);

    // --- G. Gestión de Usuarios ---
    Route::get('users/available-roles', [TenantUserController::class, 'getAvailableRoles'])->middleware('permission:manage_users');
    Route::apiResource('users', TenantUserController::class)->middleware('permission:manage_users');
});