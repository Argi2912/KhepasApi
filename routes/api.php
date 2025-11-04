<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\ExchangeTransactionController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Rutas API versionadas bajo el prefijo /v1.
|
*/

Route::prefix('v1')->group(function () {

    // =========================================================
    // 1. MÓDULO DE AUTENTICACIÓN (Rutas Públicas)
    // Prefijo: /api/v1/auth
    // =========================================================
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('register', 'register');
        Route::post('login', 'login');

        // Rutas de Auth que requieren estar autenticado
        Route::middleware('auth:api')->group(function () {
            Route::post('updateProfile', 'updateProfile');
            Route::post('logout', 'logout');
            Route::post('refresh', 'refresh');
            Route::get('me', 'me');
        });
    });

    // =========================================================
    // 2. GRUPO DE RUTAS PROTEGIDAS (Requieren Token 'auth:api')
    // =========================================================
    Route::middleware('auth:api')->group(function () {

        // ---------------------------------------------------------
        // MÓDULO DE USUARIOS (Clientes, Proveedores, etc.)
        // Prefijo: /api/v1/users
        // ---------------------------------------------------------
        Route::apiResource('users', UserController::class);

        // ---------------------------------------------------------
        // MÓDULO DE CUENTAS CONTABLES
        // Prefijo: /api/v1/accounts
        // ---------------------------------------------------------
        Route::apiResource('accounts', AccountController::class);
        
        // ---------------------------------------------------------
        // MÓDULO DE DIVISAS
        // Prefijo: /api/v1/currencies
        // ---------------------------------------------------------
        Route::apiResource('currencies', CurrencyController::class);

        // ---------------------------------------------------------
        // MÓDULO DE TASAS DE CAMBIO
        // Prefijo: /api/v1/exchange-rates
        // ---------------------------------------------------------
        Route::prefix('exchange-rates')->controller(ExchangeRateController::class)->group(function () {
            // !! IMPORTANTE: Ruta específica 'latest' DEBE ir ANTES de la ruta genérica '{id}'
            Route::get('latest', 'getLatestRate');
            
            // Rutas CRUD (reemplaza a apiResource para ordenar 'latest' primero)
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{exchange_rate}', 'show'); // {exchange_rate} es el comodín
            Route::put('/{exchange_rate}', 'update');
            Route::delete('/{exchange_rate}', 'destroy');
        });

        // ---------------------------------------------------------
        // MÓDULO DE CAJAS (Cashes) Y CIERRES
        // Prefijo: /api/v1/cashes
        // ---------------------------------------------------------
        Route::prefix('cashes')->controller(CashController::class)->group(function () {
            // Rutas de Cierre de Caja
            Route::post('closure/start', 'startClosure')->middleware('permission:start cash closure');
            Route::post('closure/end', 'endClosure')->middleware('permission:end cash closure');
            
            // Rutas CRUD para Cajas (apiResource movido aquí para agrupar)
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('/{cash}', 'show');
            Route::put('/{cash}', 'update');
            Route::delete('/{cash}', 'destroy');
        });

        // ---------------------------------------------------------
        // MÓDULO DE TRANSACCIONES (Generales e Intercambios)
        // Prefijo: /api/v1/transactions
        // ---------------------------------------------------------
        Route::prefix('transactions')->group(function () {
            
            // Transacciones Generales (CXP, CXC, Ingresos, Egresos)
            Route::controller(TransactionController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('{transaction}', 'show');
                Route::post('register-cxp', 'storeAccountPayable');
                Route::post('register-cxc', 'storeAccountReceivable');
                Route::post('register-ingress', 'storeDirectIngress');
                Route::post('register-egress', 'storeDirectEgress');
                Route::post('pay-debt', 'payAccountPayable');
                Route::post('receive-payment', 'receiveAccountReceivable');
            });

            // !! CORRECCIÓN 405:
            // Se mueve la ruta de 'executeExchange' aquí para que coincida con la URL del frontend:
            // POST /api/v1/transactions/execute-exchange
            Route::post('execute-exchange', [ExchangeTransactionController::class, 'executeExchange']);
        });

        // ---------------------------------------------------------
        // MÓDULO DE ESTADÍSTICAS Y REPORTES
        // Prefijo: /api/v1/stats
        // ---------------------------------------------------------
        Route::prefix('stats')->controller(StatsController::class)->group(function () {
            Route::get('balance-general', 'getNetBalance');
            Route::get('production-by-broker', 'getBrokerProduction');
            Route::get('total-volume', 'getVolumeOperated');
            Route::get('total-commissions', 'getCommissionTotals');
        });

        /*
        // --- RUTA ANTIGUA DE INTERCAMBIO (ELIMINADA) ---
        // Se elimina este prefijo '/exchange' porque la ruta se movió a '/transactions'
        
        Route::prefix('exchange')->controller(ExchangeTransactionController::class)->group(function () {
             Route::post('execute', 'executeExchange'); // <-- Esta era la ruta incorrecta
        });
        */

    });
});