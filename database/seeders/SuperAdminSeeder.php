<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Usuario Maestro (Sin Tenant ID)
        $superAdmin = User::firstOrCreate(
            ['email' => 'super@admin.com'],
            [
                'tenant_id' => null, 
                'name' => 'Super Admin',
                'password' => Hash::make('password'), 
            ]
        );
        
        $superAdmin->assignRole('superadmin');
    }
}