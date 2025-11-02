<?php
namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $tenant = Tenant::where('name', 'Demo Exchange Services Inc.')->first();
        if (! $tenant) {
            $this->command->info('Tenant no encontrado. Ejecute TenantSeeder primero.');
            return;
        }

        $adminRole    = Role::where('name', 'Tenant Admin')->first();
        $brokerRole   = Role::where('name', 'Broker')->first();
        $clientRole   = Role::where('name', 'Client')->first();
        $providerRole = Role::where('name', 'Provider')->first();

        // 1. Usuario Administrador del Tenant (ya existente, asegúrate de que use el nuevo rol)
        $admin = User::firstOrCreate(['email' => 'admin@demo.com'], [
            'tenant_id'     => $tenant->id,
            'first_name'    => 'Admin',
            'last_name'     => 'Tenant',
            'phone_number'  => '555-1234',
            'address'       => 'Demo Street 123',
            'date_of_birth' => '1990-01-01',
            'password'      => Hash::make('password'),
            'is_active'     => true,
            'is_admin'      => false,
        ]);
        if ($adminRole) {
            $admin->assignRole($adminRole);
        }

        // 2. Usuario Corredor de Prueba
        $broker = User::firstOrCreate(['email' => 'broker@demo.com'], [
            'tenant_id'  => $tenant->id,
            'first_name' => 'Corredor',
            'last_name'  => 'Prueba',
            'password'   => Hash::make('password'),
        ]);
        if ($brokerRole) {
            $broker->assignRole($brokerRole);
        }

        // 3. Usuario Cliente de Prueba
        $client = User::firstOrCreate(['email' => 'client@demo.com'], [
            'tenant_id'  => $tenant->id,
            'first_name' => 'Cliente',
            'last_name'  => 'Nuevo',
            'password'   => Hash::make('password'),
        ]);
        if ($clientRole) {
            $client->assignRole($clientRole);
        }

        // 4. Usuario Proveedor de Prueba
        $provider = User::firstOrCreate(['email' => 'provider@demo.com'], [
            'tenant_id'  => $tenant->id,
            'first_name' => 'Proveedor',
            'last_name'  => 'Servicios',
            'password'   => Hash::make('password'),
        ]);
        if ($providerRole) {
            $provider->assignRole($providerRole);
        }

    }
}
