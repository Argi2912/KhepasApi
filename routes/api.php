<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\ExchangeTransactionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Aquí es donde puedes registrar las rutas de API para tu aplicación. 
| Estas rutas son cargadas por el RouteServiceProvider dentro de un grupo 
| que está asignado al grupo de middleware "api".
|
*/

// Versión principal de la API
Route::prefix('v1')->group(function () {
    
    // =========================================================
    // 1. MÓDULO DE AUTENTICACIÓN (auth)
    // =========================================================
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        
        // Rutas Públicas
        Route::post('register', 'register');
        Route::post('login', 'login');
        
        // Rutas Protegidas (Requieren Token)
        Route::middleware('auth:api')->group(function () {
            Route::post('logout', 'logout');
            Route::post('refresh', 'refresh');
            Route::get('me', 'me');
        });
    });

    // =========================================================
    // GRUPO DE RUTAS PROTEGIDAS POR AUTH (Todos requieren token)
    // =========================================================
    Route::middleware('auth:api')->group(function () {
        
        // ---------------------------------------------------------
        // 2. MÓDULO DE TRANSACCIONES Y CONTABILIDAD (transactions)
        // ---------------------------------------------------------
        Route::prefix('transactions')->controller(TransactionController::class)->group(function () {
            
            // Listado y Detalle
            Route::get('/', 'index'); 
            Route::get('{transaction}', 'show'); 

            // 2.1. Creación de Transacciones (Asientos contables basados en el documento)
            Route::post('register-cxp', 'storeAccountPayable');
            Route::post('register-cxc', 'storeAccountReceivable');
            Route::post('register-ingress', 'storeDirectIngress');
            Route::post('register-egress', 'storeDirectEgress');
            
            // 2.2. Saldar Cuentas (Pagos/Cobros)
            Route::post('pay-debt', 'payAccountPayable');
            Route::post('receive-payment', 'receiveAccountReceivable');
        });
        
        // ---------------------------------------------------------
        // 3. MÓDULO DE USUARIOS (users)
        // ---------------------------------------------------------
        // Maneja el CRUD de Clientes, Corredores, Proveedores (Bases de Datos de Entidades)
        Route::apiResource('users', UserController::class);
        
        // ---------------------------------------------------------
        // 4. MÓDULO DE ESTADÍSTICAS Y REPORTES (stats)
        // ---------------------------------------------------------
        Route::prefix('stats')->controller(StatsController::class)->group(function () {
            
            // Dashboard Home (Balance General)
            Route::get('balance-general', 'getNetBalance');
            
            // Estadísticas de Rendimiento
            Route::get('production-by-broker', 'getBrokerProduction');
            Route::get('total-volume', 'getVolumeOperated');
            Route::get('total-commissions', 'getCommissionTotals');
        });

        // ---------------------------------------------------------
        // 5. MÓDULO DE CAJA Y PLATAFORMAS (cashes)
        // ---------------------------------------------------------
        Route::apiResource('cashes', CashController::class);
        
        // Rutas específicas para el Cierre de Caja
        Route::controller(CashController::class)->group(function () {
            Route::post('cashes/closure/start', 'startClosure')->middleware('permission:start cash closure');
            Route::post('cashes/closure/end', 'endClosure')->middleware('permission:end cash closure');
        });

        // ---------------------------------------------------------
        // 6. MÓDULO DE TASAS DE CAMBIO (exchange-rates)
        // ---------------------------------------------------------
        // CRUD de las tasas de cambio históricas
        Route::apiResource('exchange-rates', ExchangeRateController::class);

        // ---------------------------------------------------------
        // 7. MÓDULO DE INTERCAMBIO DE DIVISAS (exchange)
        // ---------------------------------------------------------
        Route::prefix('exchange')->controller(ExchangeTransactionController::class)->group(function () {
            // Ejecutar la operación de cambio (asiento contable completo)
            Route::post('execute', 'executeExchange');
            // Route::get('history', 'index'); // Opcional: listar historial
        });
    });
});