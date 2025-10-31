<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Spatie\Permission\Models\Role;

class AssignRoleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * php artisan app:assign-role {userId} {roleName}
     */
    protected $signature = 'app:assign-role {userId} {roleName=admin}';

    /**
     * The console command description.
     */
    protected $description = 'Asigna un rol a un usuario (por defecto admin)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $userId = $this->argument('userId');
        $roleName = $this->argument('roleName');

        $user = User::find($userId);

        if (!$user) {
            $this->error("❌ Usuario con ID {$userId} no encontrado.");
            return Command::FAILURE;
        }

        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'api']);

        $user->assignRole($role);

        $this->info("✅ Rol '{$roleName}' asignado correctamente al usuario '{$user->name}' (ID: {$user->id}).");

        return Command::SUCCESS;
    }
}
