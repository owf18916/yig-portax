<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaxCaseNumberGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Entity $entity;
    private FiscalYear $fiscalYear;
    private Period $marchPeriod;
    private Period $aprilPeriod;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insert([
            'id' => 1,
            'code' => 'ADMIN',
            'name' => 'Administrator',
            'permissions' => json_encode(['manage_cases']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('currencies')->insert([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'symbol' => 'Rp',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('case_statuses')->insert([
            'id' => 1,
            'code' => 'DRAFT',
            'name' => 'Draft',
            'sort_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->entity = Entity::create([
            'code' => 'PASI',
            'name' => 'PT. Autocomp Systems Indonesia',
            'entity_type' => 'HOLDING',
            'tax_id' => '07.123.456.7-901.000',
        ]);

        $this->fiscalYear = FiscalYear::create([
            'year' => 2026,
            'start_date' => '2025-04-01',
            'end_date' => '2026-03-31',
            'is_active' => true,
            'is_closed' => false,
        ]);

        $this->marchPeriod = Period::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_code' => '2026-03',
            'year' => 2026,
            'month' => 3,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
            'is_closed' => false,
        ]);

        $this->aprilPeriod = Period::create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_code' => '2025-04',
            'year' => 2025,
            'month' => 4,
            'start_date' => '2025-04-01',
            'end_date' => '2025-04-30',
            'is_closed' => false,
        ]);

        $this->user = User::factory()->create([
            'entity_id' => $this->entity->id,
            'role_id' => 1,
        ]);
    }

    public function test_creating_cit_spt_generates_persists_and_returns_case_number(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/tax-cases', $this->payload([
            'case_type' => 'CIT',
            'period_id' => $this->marchPeriod->id,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.case_number', 'PA26MarC');

        $caseId = $response->json('data.id');

        $this->assertDatabaseHas('tax_cases', [
            'id' => $caseId,
            'case_number' => 'PA26MarC',
            'case_type' => 'CIT',
            'entity_id' => $this->entity->id,
            'period_id' => $this->marchPeriod->id,
        ]);
    }

    public function test_empty_or_client_supplied_case_number_is_ignored(): void
    {
        $emptyResponse = $this->actingAs($this->user)->postJson('/api/tax-cases', $this->payload([
            'case_type' => 'CIT',
            'period_id' => $this->marchPeriod->id,
            'case_number' => '',
        ]));

        $emptyResponse->assertCreated()
            ->assertJsonPath('data.case_number', 'PA26MarC');

        $vatResponse = $this->actingAs($this->user)->postJson('/api/tax-cases', $this->payload([
            'case_type' => 'VAT',
            'period_id' => $this->marchPeriod->id,
            'case_number' => 'USER-FORGED-001',
        ]));

        $vatResponse->assertCreated()
            ->assertJsonPath('data.case_number', 'PA26MarV');

        $this->assertDatabaseMissing('tax_cases', [
            'case_number' => 'USER-FORGED-001',
        ]);
    }

    public function test_cit_and_vat_numbers_are_different_for_the_same_period(): void
    {
        $cit = $this->actingAs($this->user)->postJson('/api/tax-cases', $this->payload([
            'case_type' => 'CIT',
            'period_id' => $this->marchPeriod->id,
        ]));

        $vat = $this->actingAs($this->user)->postJson('/api/tax-cases', $this->payload([
            'case_type' => 'VAT',
            'period_id' => $this->marchPeriod->id,
        ]));

        $cit->assertCreated()->assertJsonPath('data.case_number', 'PA26MarC');
        $vat->assertCreated()->assertJsonPath('data.case_number', 'PA26MarV');

        $this->assertNotSame($cit->json('data.case_number'), $vat->json('data.case_number'));
    }

    public function test_duplicate_entity_case_type_and_period_is_rejected_without_partial_create(): void
    {
        $payload = $this->payload([
            'case_type' => 'CIT',
            'period_id' => $this->marchPeriod->id,
        ]);

        $this->actingAs($this->user)->postJson('/api/tax-cases', $payload)->assertCreated();

        $beforeCount = TaxCase::count();
        $beforeWorkflowCount = DB::table('workflow_histories')->count();

        $this->actingAs($this->user)->postJson('/api/tax-cases', $payload)
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame($beforeCount, TaxCase::count());
        $this->assertSame($beforeWorkflowCount, DB::table('workflow_histories')->count());
    }

    public function test_cit_requires_march_period_for_generation(): void
    {
        $this->actingAs($this->user)->postJson('/api/tax-cases', $this->payload([
            'case_type' => 'CIT',
            'period_id' => $this->aprilPeriod->id,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('tax_cases', 0);
    }

    private function payload(array $overrides = []): array
    {
        $periodId = $overrides['period_id'] ?? $this->marchPeriod->id;

        return array_merge([
            'entity_id' => $this->entity->id,
            'case_type' => 'CIT',
            'fiscal_year_id' => $this->fiscalYear->id,
            'period_id' => $periodId,
            'disputed_amount' => 5000000000,
            'currency_id' => 1,
        ], $overrides);
    }
}
