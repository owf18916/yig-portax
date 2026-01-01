<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrenciesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('currencies')->insert([
            [
                'code' => 'IDR',
                'name' => 'Indonesian Rupiah',
                'symbol' => 'Rp',
                'decimal_places' => 2,
                'exchange_rate' => 1.00,
                'last_updated_at' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'USD',
                'name' => 'United States Dollar',
                'symbol' => '$',
                'decimal_places' => 2,
                'exchange_rate' => 15650.00, // Approximate IDR to USD rate
                'last_updated_at' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'JPY',
                'name' => 'Japanese Yen',
                'symbol' => '¥',
                'decimal_places' => 0,
                'exchange_rate' => 108.50, // Approximate IDR to JPY rate
                'last_updated_at' => now(),
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
