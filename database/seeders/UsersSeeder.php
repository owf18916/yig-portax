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
        $users = [];

        // Add admin user
        $users[] = [
            'name' => 'Admin User',
            'email' => 'onnewulang.fajri@id.yazaki.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Burzum*666'),
            'entity_id' => 1,
            'role_id' => 1, // admin
            'phone' => '',
            'position' => 'System Administrator',
            'department' => 'IT',
            'last_login_at' => now(),
            'is_active' => true,
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Add user PASI
        $users[] = [
            'name' => 'Novia',
            'email' => 'novia.hardianti@id.yazaki.com',
            'email_verified_at' => now(),
            'password' => Hash::make('PASI@p0rt4x'),
            'entity_id' => 1,
            'role_id' => 2, // manager
            'phone' => '',
            'position' => 'Supervisor',
            'department' => 'Tax',
            'last_login_at' => now(),
            'is_active' => true,
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Add user JAI
        $users[] = [
            'name' => 'Intan Saraswati',
            'email' => 'intan.saraswati@id.yazaki.com',
            'email_verified_at' => now(),
            'password' => Hash::make('JAI@p0rt4x'),
            'entity_id' => 6,
            'role_id' => 3, // staff
            'phone' => '',
            'position' => 'Supervisor',
            'department' => 'Tax',
            'last_login_at' => now(),
            'is_active' => true,
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        
        $users[] = [
            'name' => 'Dedy',
            'email' => 'dedy.hariawan@id.yazaki.com',
            'email_verified_at' => now(),
            'password' => Hash::make('JAI@p0rt4x'),
            'entity_id' => 6,
            'role_id' => 2, // staff
            'phone' => '',
            'position' => 'Manager',
            'department' => 'Tax',
            'last_login_at' => now(),
            'is_active' => true,
            'remember_token' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('users')->insert($users);
    }
}
