<?php

namespace Tests\Feature;

use App\Models\AppealDecision;
use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\ObjectionDecision;
use App\Models\RefundProcess;
use App\Models\Role;
use App\Models\SkpRecord;
use App\Models\SupremeCourtDecision;
use App\Models\TaxCase;
use App\Models\User;
use App\Models\WorkflowHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillHistoricalRefundsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $caseSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $entity = Entity::create(['code' => 'BF', 'name' => 'Backfill', 'entity_type' => 'AFFILIATE', 'tax_id' => 'BF-TAX']);
        $role = Role::create(['code' => 'STAFF', 'name' => 'Staff']);
        $this->user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id]);
        FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
    }

    public function test_dry_run_reports_candidate_and_makes_no_writes(): void
    {
        [$case, $decision, $history] = $this->qualifyingCandidate('0.00');
        $caseBefore = (array) DB::table('tax_cases')->find($case->id);
        $decisionBefore = (array) DB::table('skp_records')->find($decision->id);
        $historyBefore = (array) DB::table('workflow_histories')->find($history->id);

        $this->artisan('portax:backfill-historical-refunds')
            ->expectsOutputToContain('READ-ONLY DRY RUN')
            ->expectsOutputToContain('Candidate count: 1')
            ->expectsOutputToContain('Would-insert count: 1')
            ->expectsOutputToContain('zero writes performed')
            ->assertSuccessful();

        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertSame($caseBefore, (array) DB::table('tax_cases')->find($case->id));
        $this->assertSame($decisionBefore, (array) DB::table('skp_records')->find($decision->id));
        $this->assertSame($historyBefore, (array) DB::table('workflow_histories')->find($history->id));
    }

    public function test_apply_creates_canonical_refund_with_historical_attribution_and_zero_amount(): void
    {
        [$case, $decision, $history] = $this->qualifyingCandidate('0.00');
        $caseBefore = (array) DB::table('tax_cases')->find($case->id);
        $historyCount = WorkflowHistory::count();

        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Apply complete: 1 inserted; verification passed.')
            ->assertSuccessful();

        $refund = RefundProcess::sole();
        $this->assertSame($case->id, $refund->tax_case_id);
        $this->assertSame(4, $refund->stage_id);
        $this->assertSame(RefundProcess::STAGE_SOURCE_SKP, $refund->stage_source);
        $this->assertSame($decision->id, $refund->triggered_by_decision_id);
        $this->assertSame(SkpRecord::class, $refund->triggered_by_decision_type);
        $this->assertSame('0.00', $refund->refund_amount);
        $this->assertSame('pending', $refund->refund_status);
        $this->assertSame('draft', $refund->status);
        $this->assertSame($history->user_id, $refund->submitted_by);
        $this->assertSame($history->created_at->toDateTimeString(), $refund->submitted_at->toDateTimeString());
        $this->assertSame($history->created_at->toDateTimeString(), $refund->created_at->toDateTimeString());
        $this->assertSame($historyCount, WorkflowHistory::count());
        $this->assertSame($caseBefore, (array) DB::table('tax_cases')->find($case->id));
        $this->assertDatabaseCount('bank_transfer_requests', 0);
        $this->assertDatabaseCount('kian_submissions', 0);
    }

    public function test_apply_is_idempotent(): void
    {
        $this->qualifyingCandidate('25.00');

        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Apply complete: 1 inserted')
            ->assertSuccessful();
        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Candidate count: 0')
            ->expectsOutputToContain('Apply complete: 0 inserted')
            ->assertSuccessful();

        $this->assertDatabaseCount('refund_processes', 1);
    }

    public function test_existing_matching_refund_is_reported_as_already_satisfied(): void
    {
        [$case, $decision] = $this->qualifyingCandidate('10.00');
        $this->createRefund($case, $decision);

        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Candidate count: 0')
            ->expectsOutputToContain('Skipped count: 1')
            ->expectsOutputToContain('already satisfies')
            ->expectsOutputToContain('Apply complete: 0 inserted')
            ->assertSuccessful();

        $this->assertDatabaseCount('refund_processes', 1);
    }

    public function test_contradictory_submitted_history_requires_manual_review(): void
    {
        [$case] = $this->decisionOnly(true, '12.00');
        $this->history($case, true);
        $this->history($case, false);

        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Manual-review count: 1')
            ->expectsOutputToContain('contradicts Refund intent')
            ->expectsOutputToContain('Apply complete: 0 inserted')
            ->assertSuccessful();

        $this->assertDatabaseCount('refund_processes', 0);
    }

    public function test_all_supported_stages_use_deterministic_decision_linkage(): void
    {
        $expected = [];
        foreach ([4, 7, 10, 12] as $stage) {
            $case = $this->makeCase();
            $decision = $this->createDecision($case, $stage);
            $this->history($case, true, $stage);
            $expected[$stage] = [$case->id, $decision->id, $decision::class];
        }

        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Candidate count: 4')
            ->expectsOutputToContain('Apply complete: 4 inserted')
            ->assertSuccessful();

        foreach ($expected as $stage => [$caseId, $decisionId, $type]) {
            $this->assertDatabaseHas('refund_processes', [
                'tax_case_id' => $caseId,
                'stage_id' => $stage,
                'triggered_by_decision_id' => $decisionId,
                'triggered_by_decision_type' => $type,
            ]);
        }
    }

    public function test_missing_submitted_history_requires_manual_review(): void
    {
        $this->decisionOnly(true, '12.00');

        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Manual-review count: 1')
            ->expectsOutputToContain('No submitted same-stage history')
            ->assertSuccessful();

        $this->assertDatabaseCount('refund_processes', 0);
    }

    public function test_soft_deleted_same_stage_refund_is_a_collision_and_is_not_revived(): void
    {
        [$case, $decision] = $this->qualifyingCandidate('12.00');
        $refund = $this->createRefund($case, $decision);
        $refund->delete();

        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Manual-review count: 1')
            ->expectsOutputToContain('Collision count: 1')
            ->expectsOutputToContain('soft-deleted RefundProcess collision')
            ->assertSuccessful();

        $this->assertSame(1, RefundProcess::withTrashed()->count());
        $this->assertSame(0, RefundProcess::count());
    }

    public function test_multiple_candidates_are_applied_deterministically_without_fixture_case_names(): void
    {
        [$firstCase] = $this->qualifyingCandidate('1.00');
        [$secondCase] = $this->qualifyingCandidate('2.00');

        $this->artisan('portax:backfill-historical-refunds --apply')
            ->expectsOutputToContain('Candidate count: 2')
            ->expectsOutputToContain('Apply complete: 2 inserted')
            ->assertSuccessful();

        $this->assertSame(
            [$firstCase->id, $secondCase->id],
            RefundProcess::orderBy('tax_case_id')->pluck('tax_case_id')->all(),
        );
    }

    private function qualifyingCandidate(string $amount): array
    {
        [$case, $decision] = $this->decisionOnly(true, $amount);

        return [$case, $decision, $this->history($case, true)];
    }

    private function decisionOnly(bool $createRefund, string $amount): array
    {
        $case = $this->makeCase();
        $decision = SkpRecord::create([
            'tax_case_id' => $case->id,
            'skp_number' => 'SKP-'.$case->id,
            'skp_type' => 'LB',
            'skp_amount' => 100,
            'create_refund' => $createRefund,
            'refund_amount' => $amount,
            'continue_to_next_stage' => false,
        ]);

        return [$case, $decision];
    }

    private function history(TaxCase $case, bool $createRefund, int $stage = 4): WorkflowHistory
    {
        return WorkflowHistory::create([
            'tax_case_id' => $case->id,
            'stage_id' => $stage,
            'stage_from' => $stage,
            'action' => 'submitted',
            'status' => 'submitted',
            'decision_point' => 'independent_actions',
            'decision_value' => json_encode(['create_refund' => $createRefund, 'refund_amount' => 0]),
            'user_id' => $this->user->id,
        ]);
    }

    private function createDecision(TaxCase $case, int $stage): object
    {
        if ($stage === 4) {
            return SkpRecord::create([
                'tax_case_id' => $case->id,
                'skp_number' => 'SKP-'.$case->id,
                'skp_type' => 'LB',
                'skp_amount' => 100,
                'create_refund' => true,
                'refund_amount' => 0,
            ]);
        }

        $attributes = [
            'tax_case_id' => $case->id,
            'decision_number' => "DECISION-{$stage}-{$case->id}",
            'decision_date' => '2026-09-29',
            'decision_type' => 'granted',
            'decision_amount' => 100,
            'status' => 'submitted',
            'create_refund' => true,
            'refund_amount' => 0,
            'submitted_by' => $this->user->id,
            'submitted_at' => now(),
        ];

        return match ($stage) {
            7 => ObjectionDecision::create($attributes),
            10 => AppealDecision::create($attributes),
            12 => SupremeCourtDecision::create($attributes),
        };
    }

    private function makeCase(): TaxCase
    {
        $this->caseSequence++;

        return TaxCase::create([
            'user_id' => $this->user->id,
            'entity_id' => $this->user->entity_id,
            'fiscal_year_id' => FiscalYear::sole()->id,
            'currency_id' => Currency::sole()->id,
            'case_status_id' => CaseStatus::sole()->id,
            'case_number' => 'BF-'.str_pad((string) $this->caseSequence, 4, '0', STR_PAD_LEFT),
            'case_type' => 'CIT',
            'reported_amount' => 100,
            'disputed_amount' => 100,
            'current_stage' => 4,
        ]);
    }

    private function createRefund(TaxCase $case, SkpRecord $decision): RefundProcess
    {
        return RefundProcess::create([
            'tax_case_id' => $case->id,
            'stage_id' => 4,
            'refund_number' => 'EXISTING-'.$case->id,
            'refund_amount' => $decision->refund_amount,
            'refund_method' => 'bank_transfer',
            'refund_status' => 'pending',
            'status' => 'draft',
            'stage_source' => RefundProcess::STAGE_SOURCE_SKP,
            'sequence_number' => 1,
            'triggered_by_decision_id' => $decision->id,
            'triggered_by_decision_type' => SkpRecord::class,
        ]);
    }
}
