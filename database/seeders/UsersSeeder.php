<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $entities = [
            ['id' => 1, 'code' => 'PASI', 'name' => 'PT. Autocomp System Indonesia'],
            ['id' => 2, 'code' => 'PEMI', 'name' => 'PT. EDS Manufacturing Indonesia'],
            ['id' => 3, 'code' => 'SUAI', 'name' => 'PT. Subang Autocomp Indonesia'],
            ['id' => 4, 'code' => 'SAMI', 'name' => 'PT. Semarang Autocomp Indonesia'],
            ['id' => 5, 'code' => 'SAI', 'name' => 'PT. Surabaya Autocomp Indonesia'],
            ['id' => 6, 'code' => 'JAI', 'name' => 'PT. Jatim Autocomp Indonesia'],
        ];

        $positions = ['Manager', 'Supervisor', 'Staff', 'Director'];
        $departments = ['Finance', 'Tax', 'Accounting', 'Operations', 'Administration'];

        $users = [];
        $userId = 1;

        foreach ($entities as $entity) {
            // Create 3 users per entity
            for ($i = 1; $i <= 3; $i++) {
                $users[] = [
                    'id' => $userId,
                    'name' => 'User ' . $i . ' ' . $entity['code'],
                    'email' => 'user' . $i . '@' . strtolower($entity['code']) . '.co.id',
                    'email_verified_at' => now(),
                    'password' => Hash::make('password123'),
                    'entity_id' => $entity['id'],
                    'role_id' => ($i === 1) ? 2 : 3, // role_id: 1=admin, 2=manager, 3=staff
                    'phone' => '+62-812-' . str_pad($userId, 7, '0', STR_PAD_LEFT),
                    'position' => $positions[$i - 1],
                    'department' => $departments[$i - 1],
                    'last_login_at' => now(),
                    'is_active' => true,
                    'remember_token' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $userId++;
            }
        }

        // Add admin user
        $users[] = [
            'id' => $userId,
            'name' => 'Admin User',
            'email' => 'admin@portax.co.id',
            'email_verified_at' => now(),
            'password' => Hash::make('admin123'),
            'entity_id' => 1,
            'role_id' => 1, // admin
            'phone' => '+62-21-9999999',
            'position' => 'System Administrator',
            'department' => 'IT',
            'last_login_at' => now(),
            'is_active' => true,
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('users')->insert($users);
    }
}
