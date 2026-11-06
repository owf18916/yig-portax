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
        DB::table('roles')->insertOrIgnore([
            [
                'code' => 'ADMIN',
                'name' => 'Administrator',
                'description' => 'System administrator with full access',
                'permissions' => json_encode([
                    'manage_users',
                    'manage_roles',
                    'manage_entities',
                    'retry_notifications'
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'MGR',
                'name' => 'Manager',
                'description' => 'Manager with supervision access',
                'permissions' => json_encode([
                    'view_cases',
                    'view_reports',
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
            [
                'code' => 'VP',
                'name' => 'Vice President',
                'description' => 'VP with supervision access',
                'permissions' => json_encode([
                    'view_cases',
                    'view_reports',
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'CO',
                'name' => 'Coordinator',
                'description' => 'Coordinator with full access',
                'permissions' => json_encode([
                    'view_reports',
                    'manage_cases',
                    'approve_decisions'
                ]),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::transaction(function () {
            $role = \App\Models\Role::where('code', 'ADMIN')->where('is_active', true)->lockForUpdate()->first();
            if (!$role || !is_array($role->permissions)) return;
            if (!in_array('retry_notifications', $role->permissions, true)) {
                $role->update(['permissions'=>array_merge($role->permissions, ['retry_notifications'])]);
            }
        });
    }
}
