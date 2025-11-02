<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Interfaces\AccountingServiceInterface;
use App\Services\AccountingService;
class AppServiceProvider extends ServiceProvider
{

    public $bindings = [
        AccountingServiceInterface::class => AccountingService::class,
    ];
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
