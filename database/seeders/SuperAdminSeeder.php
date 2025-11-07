<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $superAdmin = User::create([
            'tenant_id' => null, // <-- Es Superadmin, no pertenece a ningún tenant
            'name' => 'Super Admin',
            'email' => 'super@admin.com',
            'password' => Hash::make('password'), // Cambia esto
        ]);
        
        $superAdmin->assignRole('superadmin');
    }
}