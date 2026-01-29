<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed master data first
        $this->call([
            RolesSeeder::class,
            FiscalYearsSeeder::class,
            PeriodsSeeder::class,
            CurrenciesSeeder::class,
            CaseStatusesSeeder::class,
            EntitiesSeeder::class,
            UsersSeeder::class,
            TaxCaseSeeder::class,
        ]);
    }
}
