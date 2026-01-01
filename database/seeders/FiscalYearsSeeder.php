<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FiscalYearsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Fiscal year: April - March (e.g., FY 2010 = Apr 2009 - Mar 2010)
     */
    public function run(): void
    {
        $fiscalYears = [];

        // Create 25 fiscal years from 2010 to 2035
        for ($year = 2010; $year <= 2035; $year++) {
            // Fiscal year starts April of previous year and ends March of current year
            $startDate = $year - 1 . '-04-01';
            $endDate = $year . '-03-31';
            
            $fiscalYears[] = [
                'year' => $year,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_active' => ($year === date('Y')) ? true : false,
                'is_closed' => ($year < date('Y')) ? true : false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('fiscal_years')->insert($fiscalYears);
    }
}
