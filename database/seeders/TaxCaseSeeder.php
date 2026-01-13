<?php

namespace Database\Seeders;

use App\Models\TaxCase;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\Currency;
use App\Models\CaseStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class TaxCaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get first user (admin)
        $user = User::first();

        // Get reference data
        $entity = Entity::first();
        $fiscalYear = FiscalYear::where('year', 2024)->first() ?? FiscalYear::first();
        $period = Period::where('fiscal_year_id', $fiscalYear->id)->first();
        $currency = Currency::where('code', 'USD')->first() ?? Currency::first();
        $draftStatus = CaseStatus::where('code', 'DRAFT')->first();

        // Create test tax cases
        TaxCase::create([
            'case_number' => 'TAX-2024-001',
            'entity_id' => $entity->id,
            'fiscal_year_id' => $fiscalYear->id,
            'period_id' => $period->id ?? null,
            'currency_id' => $currency->id,
            'case_status_id' => $draftStatus->id,
            'case_type' => 'SPT',
            'disputed_amount' => 125000.00,
            'description' => 'Test case for SPT Filing',
            'created_by' => $user->id,
            'current_stage' => 1,
        ]);

        TaxCase::create([
            'case_number' => 'TAX-2024-002',
            'entity_id' => $entity->id,
            'fiscal_year_id' => $fiscalYear->id,
            'period_id' => $period->id ?? null,
            'currency_id' => $currency->id,
            'case_status_id' => $draftStatus->id,
            'case_type' => 'APPEAL',
            'disputed_amount' => 250000.00,
            'description' => 'Test case for Appeal Process',
            'created_by' => $user->id,
            'current_stage' => 1,
        ]);

        TaxCase::create([
            'case_number' => 'TAX-2024-003',
            'entity_id' => $entity->id,
            'fiscal_year_id' => $fiscalYear->id,
            'period_id' => $period->id ?? null,
            'currency_id' => $currency->id,
            'case_status_id' => $draftStatus->id,
            'case_type' => 'OBJECTION',
            'disputed_amount' => 500000.00,
            'description' => 'Test case for Objection Process',
            'created_by' => $user->id,
            'current_stage' => 1,
        ]);
    }
}
