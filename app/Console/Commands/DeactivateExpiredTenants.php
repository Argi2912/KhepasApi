<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class DeactivateExpiredTenants extends Command
{
    // Nombre del comando para ejecutarlo manualmente o en schedule
    protected $signature = 'tenants:deactivate-expired';

    protected $description = 'Desactiva los tenants cuya suscripción o prueba ha expirado';

    public function handle()
    {
        $now = now();

        // Buscar tenants activos cuya fecha de fin ya pasó
        $expiredTenants = Tenant::where('is_active', true)
            ->where('subscription_ends_at', '<', $now)
            ->get();

        $count = 0;

        foreach ($expiredTenants as $tenant) {
            $tenant->update(['is_active' => false]);
            Log::info("Tenant ID {$tenant->id} ({$tenant->name}) desactivado por expiración.");
            $count++;
        }

        $this->info("Se han desactivado {$count} tenants expirados.");
    }
}
