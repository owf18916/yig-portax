<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PeriodsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Monthly periods for VAT reporting (12 periods per fiscal year)
     */
    public function run(): void
    {
        $periods = [];
        $periodId = 1;

        // For each fiscal year (2010-2035)
        for ($year = 2010; $year <= 2035; $year++) {
            // Get fiscal year id
            $fiscalYearId = $year - 2009; // 2010 = id 1, 2011 = id 2, etc.
            
            // Create 12 monthly periods
            // FY starts in April (month 4) and ends in March (month 3)
            for ($month = 1; $month <= 12; $month++) {
                // Calculate calendar year for start and end dates
                $calendarYear = ($month >= 4) ? $year - 1 : $year;
                $calendarMonth = ($month >= 4) ? $month : $month + 12; // Adjust for April-March fiscal year
                $actualMonth = ($month >= 4) ? $month : $month;
                
                // For simplicity, use calendar year-month format
                $startMonth = ($month < 4) ? ($year) : ($year - 1);
                $startMonthNum = ($month < 4) ? $month : $month;
                
                $endMonth = $month;
                $endMonthNum = $month;
                
                if ($month === 12) {
                    // December
                    $startDate = ($year - 1) . '-12-01';
                    $endDate = ($year - 1) . '-12-31';
                    $periodCode = ($year - 1) . '-12';
                    $periodYear = $year - 1;
                } elseif ($month < 4) {
                    // Jan-Mar (of current year)
                    $startDate = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';
                    $endDate = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . $this->getDaysInMonth($year, $month);
                    $periodCode = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
                    $periodYear = $year;
                } else {
                    // Apr-Nov (of previous year)
                    $startDate = ($year - 1) . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';
                    $endDate = ($year - 1) . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . $this->getDaysInMonth($year - 1, $month);
                    $periodCode = ($year - 1) . '-' . str_pad($month, 2, '0', STR_PAD_LEFT);
                    $periodYear = $year - 1;
                }
                
                $periods[] = [
                    'id' => $periodId++,
                    'fiscal_year_id' => $fiscalYearId,
                    'period_code' => $periodCode,
                    'year' => $periodYear,
                    'month' => $month,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'is_closed' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Insert in chunks to avoid memory issues
        foreach (array_chunk($periods, 100) as $chunk) {
            DB::table('periods')->insert($chunk);
        }
    }

    /**
     * Get number of days in a month
     */
    private function getDaysInMonth($year, $month): int
    {
        return (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    }
}
