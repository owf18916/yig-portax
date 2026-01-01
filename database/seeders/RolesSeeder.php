<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('roles')->insert([
            [
                'code' => 'ADMIN',
                'name' => 'Administrator',
                'description' => 'System administrator with full access',
                'permissions' => json_encode([
                    'manage_users',
                    'manage_roles',
                    'manage_entities',
                    'view_reports',
                    'manage_cases',
                    'approve_decisions'
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'MANAGER',
                'name' => 'Manager',
                'description' => 'Manager with supervision access',
                'permissions' => json_encode([
                    'view_cases',
                    'submit_cases',
                    'view_reports',
                    'approve_submissions'
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'STAFF',
                'name' => 'Staff',
                'description' => 'Regular staff member',
                'permissions' => json_encode([
                    'view_cases',
                    'submit_cases',
                    'upload_documents'
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
