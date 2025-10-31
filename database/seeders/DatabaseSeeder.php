<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
// 💡 Importa las clases de Spatie
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // 1. Definición y Creación de Permisos
        // -----------------------------------------------------
        $permissions = [
            'view client database',
            'view provider database',
            'view broker database',
            'view admin database',
            'view request history', // Corregido: "history" en la ruta, "database" en tu seeder. Usaré "history".
            // Agrega aquí cualquier otro permiso de la aplicación
        ];
        
        // 1.1 Crea todos los permisos definidos
        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName]);
        }

        // 2. Crear el Rol 'admin'
        // -----------------------------------------------------
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        
        // 2.1 Asigna TODOS los permisos recién creados al rol de administrador
        // El método givePermissionTo acepta un array de nombres de permisos.
        $adminRole->givePermissionTo($permissions);

        // 3. Crear el Usuario Admin
        // -----------------------------------------------------
        $adminUser = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@khepas.com',
            'password' => bcrypt('password'),
        ]);

        // 4. Asignar el Rol 'admin' al Usuario
        // -----------------------------------------------------
        $adminUser->assignRole($adminRole); // Asignamos el objeto Role que ya creamos.
        
        // 5. Limpiar la caché de permisos (Buena práctica)
        // -----------------------------------------------------
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }
}