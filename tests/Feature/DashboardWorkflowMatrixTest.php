<?php

namespace Tests\Feature;

use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\Role;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardWorkflowMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_affiliate_sees_only_own_entity_and_missing_cit_case_has_create_action(): void
    {
        [$affiliate, $other] = $this->entities();
        $user = $this->user($affiliate);
        $period = $this->period('2024-03', 2024, 3);

        $response = $this->actingAs($user)->getJson('/api/dashboard/workflow-matrix?tax_category=CIT&per_page=20');

        $response->assertOk()
            ->assertJsonPath('data.rows.0.entity.id', $affiliate->id)
            ->assertJsonPath('data.rows.0.stages.spt.action.name', 'CreateCITCase')
            ->assertJsonPath('data.rows.0.stages.spt.action.query.entity_id', $affiliate->id)
            ->assertJsonPath('data.rows.0.stages.spt.action.query.period_id', $period->id);

        $this->assertNotEquals($other->id, $response->json('data.rows.0.entity.id'));
    }

    public function test_holding_user_can_filter_authorized_entities_and_unauthorized_affiliate_filter_is_forbidden(): void
    {
        [$affiliate, $other, $holding] = $this->entities();
        $this->period('2024-03', 2024, 3);

        $holdingUser = $this->user($holding);
        $this->actingAs($holdingUser)
            ->getJson("/api/dashboard/workflow-matrix?tax_category=CIT&entity_id={$other->id}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.entity.id', $other->id);

        $affiliateUser = $this->user($affiliate);
        $this->actingAs($affiliateUser)
            ->getJson("/api/dashboard/workflow-matrix?tax_category=CIT&entity_id={$other->id}")
            ->assertForbidden();
    }

    public function test_vat_rows_are_monthly_descending_and_reject_pre_2013_floor(): void
    {
        [$affiliate] = $this->entities();
        $user = $this->user($affiliate);
        $this->period('2013-02', 2013, 2);
        $this->period('2013-03', 2013, 3);
        $this->period('2013-04', 2013, 4);

        $this->actingAs($user)
            ->getJson('/api/dashboard/workflow-matrix?tax_category=VAT&from_period=2013-02&to_period=2013-04')
            ->assertUnprocessable();

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard/workflow-matrix?tax_category=VAT&from_period=2013-03&to_period=2013-04');

        $response->assertOk();
        $this->assertSame(['2013-04', '2013-03'], collect($response->json('data.rows'))->pluck('period.period_code')->all());
    }

    public function test_existing_case_resolves_submitted_stage_and_refund(): void
    {
        [$affiliate] = $this->entities();
        $user = $this->user($affiliate);
        $period = $this->period('2024-03', 2024, 3);
        $case = $this->taxCase($affiliate, $user, $period, 'CIT');
        $case->workflowHistories()->create([
            'stage_id' => 1,
            'status' => 'submitted',
            'action' => 'submitted',
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/dashboard/workflow-matrix?tax_category=CIT');

        $response->assertOk()
            ->assertJsonPath('data.rows.0.tax_case.id', $case->id)
            ->assertJsonPath('data.rows.0.stages.spt.status', 'completed')
            ->assertJsonPath('data.rows.0.stages.sp2.status', 'action_required')
            ->assertJsonPath('data.rows.0.refund.status', 'not_available');
    }

    public function test_matrix_query_count_stays_bounded_for_generated_rows(): void
    {
        [$affiliate, $other, $holding] = $this->entities();
        $user = $this->user($holding);
        foreach (range(1, 6) as $offset) {
            $month = str_pad((string) $offset, 2, '0', STR_PAD_LEFT);
            $this->period("2024-{$month}", 2024, $offset);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->getJson('/api/dashboard/workflow-matrix?tax_category=VAT&from_period=2024-01&to_period=2024-06&per_page=10')
            ->assertOk();

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $queryCount);
        $this->assertNotSame($affiliate->id, $other->id);
    }

    public function test_include_completed_accepts_normalized_boolean_values_and_safe_default(): void
    {
        [$affiliate] = $this->entities();
        $user = $this->user($affiliate);
        $this->period('2024-03', 2024, 3);

        foreach (['1', '0', 'true', 'false', null] as $value) {
            $query = '/api/dashboard/workflow-matrix?tax_category=CIT';
            if ($value !== null) {
                $query .= "&include_completed={$value}";
            }

            $this->actingAs($user)->getJson($query)->assertOk();
        }
    }

    public function test_include_completed_rejects_unknown_boolean_values(): void
    {
        [$affiliate] = $this->entities();
        $user = $this->user($affiliate);
        $this->period('2024-03', 2024, 3);

        foreach (['abc', ''] as $value) {
            $this->actingAs($user)
                ->getJson("/api/dashboard/workflow-matrix?tax_category=CIT&include_completed={$value}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['include_completed']);
        }
    }

    public function test_default_vat_request_uses_latest_twelve_months(): void
    {
        [$affiliate] = $this->entities();
        $user = $this->user($affiliate);
        $this->periodRange('2024-01', 30);

        $response = $this->actingAs($user)
            ->getJson('/api/dashboard/workflow-matrix?tax_category=VAT');

        $response->assertOk();
        $periodCodes = collect($response->json('data.rows'))->pluck('period.period_code');

        $this->assertCount(12, $periodCodes);
        $this->assertSame('2026-06', $periodCodes->first());
        $this->assertSame('2025-07', $periodCodes->last());
    }

    public function test_vat_range_of_twenty_four_months_passes_and_over_twenty_four_fails(): void
    {
        [$affiliate] = $this->entities();
        $user = $this->user($affiliate);
        $this->periodRange('2024-01', 25);

        $this->actingAs($user)
            ->getJson('/api/dashboard/workflow-matrix?tax_category=VAT&from_period=2024-01&to_period=2025-12')
            ->assertOk();

        $this->actingAs($user)
            ->getJson('/api/dashboard/workflow-matrix?tax_category=VAT&from_period=2024-01&to_period=2026-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_period']);
    }

    public function test_category_specific_filters_do_not_cross_validate(): void
    {
        [$affiliate] = $this->entities();
        $user = $this->user($affiliate);
        $period = $this->period('2024-03', 2024, 3);

        $this->actingAs($user)
            ->getJson('/api/dashboard/workflow-matrix?tax_category=CIT&from_period=2024-01&to_period=2024-03')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_period']);

        $this->actingAs($user)
            ->getJson("/api/dashboard/workflow-matrix?tax_category=VAT&fiscal_year_id={$period->fiscal_year_id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_year_id']);

        $this->actingAs($user)
            ->getJson('/api/dashboard/workflow-matrix?tax_category=VAT&from_period=2024-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from_period']);
    }

    private function entities(): array
    {
        $affiliate = Entity::create(['code' => 'AFF', 'name' => 'Affiliate', 'entity_type' => 'AFFILIATE', 'tax_id' => 'AFF-TAX']);
        $other = Entity::create(['code' => 'OTH', 'name' => 'Other Affiliate', 'entity_type' => 'AFFILIATE', 'tax_id' => 'OTH-TAX']);
        $holding = Entity::create(['code' => 'HLD', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'HLD-TAX']);

        return [$affiliate, $other, $holding];
    }

    private function user(Entity $entity): User
    {
        $role = Role::firstOrCreate(['code' => 'user'], ['name' => 'User']);

        return User::factory()->create([
            'entity_id' => $entity->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function period(string $code, int $year, int $month): Period
    {
        $fiscalYear = FiscalYear::firstOrCreate(
            ['year' => $month <= 3 ? $year : $year + 1],
            ['start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"]
        );

        return Period::firstOrCreate(
            ['period_code' => $code, 'fiscal_year_id' => $fiscalYear->id],
            ['year' => $year, 'month' => $month, 'start_date' => "{$code}-01", 'end_date' => "{$code}-28", 'is_closed' => true]
        );
    }

    private function periodRange(string $start, int $count): void
    {
        [$year, $month] = array_map('intval', explode('-', $start));

        foreach (range(0, $count - 1) as $offset) {
            $date = now()->setDate($year, $month, 1)->addMonths($offset);
            $code = $date->format('Y-m');

            $this->period($code, (int) $date->format('Y'), (int) $date->format('n'));
        }
    }

    private function taxCase(Entity $entity, User $user, Period $period, string $type): TaxCase
    {
        Currency::firstOrCreate(['code' => 'IDR'], ['name' => 'Rupiah', 'symbol' => 'Rp']);
        CaseStatus::firstOrCreate(['code' => 'OPEN'], ['name' => 'Open']);

        return TaxCase::create([
            'user_id' => $user->id,
            'entity_id' => $entity->id,
            'fiscal_year_id' => $period->fiscal_year_id,
            'period_id' => $period->id,
            'currency_id' => Currency::first()->id,
            'case_status_id' => CaseStatus::first()->id,
            'case_number' => "{$entity->code}{$period->period_code}{$type}",
            'case_type' => $type,
            'reported_amount' => 100,
            'disputed_amount' => 100,
        ]);
    }
}
