<?php

namespace Tests\Feature;

use App\Models\CaseStatus;
use App\Models\BankTransferRequest;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\RefundProcess;
use App\Models\Role;
use App\Models\SupremeCourtDecision;
use App\Models\SupremeCourtSubmission;
use App\Models\TaxCase;
use App\Models\User;
use App\Models\WorkflowHistory;
use App\Services\MainWorkflowTransitionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DecisionPointContractNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TaxCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $entity = Entity::create(['code' => 'P1C', 'name' => 'Phase 1C', 'entity_type' => 'AFFILIATE', 'tax_id' => 'P1C-TAX']);
        $role = Role::create(['code' => 'STAFF', 'name' => 'Staff', 'is_active' => true, 'permissions' => ['submit_cases']]);
        $this->user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        // The workflow's existing seeded contract is SUBMITTED = 2. PostgreSQL
        // sequences survive transaction rollback, so reference IDs must be stable.
        $open = CaseStatus::forceCreate(['id' => 1, 'code' => 'OPEN', 'name' => 'Open']);
        CaseStatus::forceCreate(['id' => 2, 'code' => 'SUBMITTED', 'name' => 'Submitted']);

        $this->case = TaxCase::create([
            'user_id' => $this->user->id,
            'entity_id' => $entity->id,
            'fiscal_year_id' => $year->id,
            'currency_id' => $currency->id,
            'case_status_id' => $open->id,
            'case_number' => 'P1C-2026-01',
            'case_type' => 'CIT',
            'spt_position_type' => 'LB',
            'reported_amount' => 1000,
            'disputed_amount' => 1000,
            'current_stage' => 1,
        ]);

        $this->actingAs($this->user);
    }

    public function test_draft_preserves_status_and_submit_persists_seeded_submitted_status(): void
    {
        $this->prepareStage(4);
        $originalStatus = $this->case->case_status_id;
        $submitted = CaseStatus::where('code', 'SUBMITTED')->sole();
        $payload = [
            'action' => 'draft',
            'skp_number' => 'SKP-STATUS',
            'skp_type' => 'LB',
            'skp_amount' => 800,
            'create_refund' => false,
            'continue_to_next_stage' => false,
        ];

        $this->postJson($this->url(4), $payload)->assertOk();
        $this->assertSame($originalStatus, $this->case->fresh()->case_status_id);

        $payload['action'] = 'submit';
        $this->postJson($this->url(4), $payload)->assertOk();
        $persisted = $this->case->fresh();
        $this->assertSame($submitted->id, $persisted->case_status_id);
        $this->assertSame('SUBMITTED', $persisted->status->code);
        $this->assertSame(4, $persisted->current_stage);
        $this->assertDatabaseHas('workflow_histories', [
            'tax_case_id' => $persisted->id, 'stage_id' => 4, 'status' => 'submitted',
        ]);
    }

    public static function decisionCombinations(): array
    {
        return [
            'neither' => [false, false],
            'refund only' => [true, false],
            'continue only' => [false, true],
            'refund and continue' => [true, true],
        ];
    }

    #[DataProvider('decisionCombinations')]
    public function test_stage_four_refund_and_continue_are_independent(bool $refund, bool $continue): void
    {
        $this->prepareStage(4);
        $this->postJson($this->url(4), [
            'action' => 'submit',
            'skp_number' => 'SKP-P1C',
            'skp_type' => 'LB',
            'skp_amount' => 800,
            'create_refund' => $refund,
            'continue_to_next_stage' => $continue,
        ])->assertOk();

        $this->assertDecisionResult(4, 5, $refund, $continue, 'skpRecord');
    }

    #[DataProvider('decisionCombinations')]
    public function test_stage_seven_refund_and_continue_are_independent(bool $refund, bool $continue): void
    {
        $this->prepareStage(7);
        $this->postJson($this->url(7), $this->decisionPayload('OBJ-P1C', $refund, $continue))
            ->assertOk();

        $this->assertDecisionResult(7, 8, $refund, $continue, 'objectionDecision');
    }

    #[DataProvider('decisionCombinations')]
    public function test_stage_ten_refund_and_continue_are_independent(bool $refund, bool $continue): void
    {
        $this->prepareStage(10);
        $this->postJson($this->url(10), $this->decisionPayload('APL-P1C', $refund, $continue))
            ->assertOk();

        $this->assertDecisionResult(10, 11, $refund, $continue, 'appealDecision');
    }

    public function test_stage_twelve_uses_english_contract_is_terminal_and_is_idempotent(): void
    {
        $this->prepareStage(12);
        SupremeCourtSubmission::create([
            'tax_case_id' => $this->case->id,
            'submission_number' => 'SC-SUB-P1C',
            'submission_date' => '2026-09-01',
            'submission_amount' => 900,
            'review_amount' => 900,
        ]);
        $payload = [
            'action' => 'submit',
            'decision_number' => 'SC-P1C',
            'decision_date' => '2026-09-29',
            'decision_type' => 'partially_granted',
            'decision_amount' => 700,
            'decision_notes' => 'Canonical English fields',
            'create_refund' => true,
        ];

        $this->postJson($this->url(12), $payload)->assertOk();

        $decision = SupremeCourtDecision::sole();
        $this->assertSame('SC-P1C', $decision->decision_number);
        $this->assertSame('2026-09-29', $decision->decision_date->toDateString());
        $this->assertSame('partially_granted', $decision->decision_type);
        $this->assertSame('700.00', $decision->decision_amount);
        $this->assertSame('Canonical English fields', $decision->decision_notes);
        $this->assertTrue($decision->create_refund);
        $this->assertSame(12, $this->case->fresh()->current_stage);
        $this->assertTrue($this->case->fresh()->is_completed);
        $this->assertDatabaseHas('refund_processes', ['tax_case_id' => $this->case->id, 'stage_id' => 12]);
        $this->assertNull($this->case->fresh()->exposureByStage()[12]['needsKian']);
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->assertDatabaseMissing('workflow_histories', ['tax_case_id' => $this->case->id, 'stage_to' => 13]);

        $this->postJson($this->url(12), $payload)->assertConflict();
        $this->assertSame(1, SupremeCourtDecision::count());
        $this->assertSame(1, RefundProcess::count());
        $this->assertSame(1, WorkflowHistory::where('stage_id', 12)->where('status', 'submitted')->count());
    }

    public function test_stage_twelve_rejects_continue_and_noncanonical_casing(): void
    {
        $this->prepareStage(12);
        $payload = $this->decisionPayload('SC-INVALID', false, false);
        $payload['continue_to_next_stage'] = true;
        $payload['decision_type'] = 'REJECTED';

        $this->postJson($this->url(12), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['decision_type', 'continue_to_next_stage']);
        $this->assertDatabaseCount('supreme_court_decisions', 0);
        $this->assertDatabaseCount('refund_processes', 0);
    }

    public function test_tax_case_detail_returns_each_refund_with_its_explicit_current_stage(): void
    {
        $skpRefund = RefundProcess::create([
            'tax_case_id' => $this->case->id,
            'stage_id' => 4,
            'stage_source' => RefundProcess::STAGE_SOURCE_SKP,
            'sequence_number' => 1,
            'refund_number' => 'REF-SKP-P1C',
            'refund_amount' => 100,
            'refund_method' => 'bank_transfer',
            'refund_status' => 'pending',
            'status' => 'draft',
        ]);
        $objectionRefund = RefundProcess::create([
            'tax_case_id' => $this->case->id,
            'stage_id' => 7,
            'stage_source' => RefundProcess::STAGE_SOURCE_OBJECTION,
            'sequence_number' => 2,
            'refund_number' => 'REF-OBJ-P1C',
            'refund_amount' => 200,
            'refund_method' => 'bank_transfer',
            'refund_status' => 'approved',
            'status' => 'approved',
        ]);
        BankTransferRequest::create([
            'refund_process_id' => $objectionRefund->id,
            'request_number' => 'BTR-OBJ-P1C',
            'transfer_amount' => 200,
            'transfer_status' => 'pending',
            'created_by' => $this->user->id,
        ]);

        $this->getJson("/api/tax-cases/{$this->case->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.refund_processes')
            ->assertJsonPath('data.refund_processes.0.id', $skpRefund->id)
            ->assertJsonPath('data.refund_processes.0.stage_id', 4)
            ->assertJsonPath('data.refund_processes.0.current_refund_stage', 1)
            ->assertJsonPath('data.refund_processes.1.id', $objectionRefund->id)
            ->assertJsonPath('data.refund_processes.1.stage_id', 7)
            ->assertJsonPath('data.refund_processes.1.current_refund_stage', 2);
    }

    private function assertDecisionResult(int $stage, int $nextStage, bool $refund, bool $continue, string $relation): void
    {
        $record = $this->case->fresh()->{$relation};
        $this->assertSame($refund, $record->create_refund);
        $this->assertSame($continue, $record->continue_to_next_stage);
        $this->assertSame($refund ? 1 : 0, RefundProcess::where('stage_id', $stage)->count());

        $history = WorkflowHistory::where('stage_id', $stage)->where('status', 'submitted')->sole();
        $this->assertSame($continue ? $nextStage : null, $history->stage_to);
        $this->assertNotSame(13, $history->stage_to);

        $guard = app(MainWorkflowTransitionGuard::class)->inspect($this->case->fresh(), $nextStage);
        $this->assertSame($continue, $guard['allowed']);
        $this->assertNotSame(13, $this->case->fresh()->current_stage);
    }

    private function decisionPayload(string $number, bool $refund, bool $continue): array
    {
        return [
            'action' => 'submit',
            'decision_number' => $number,
            'decision_date' => '2026-09-29',
            'decision_type' => 'granted',
            'decision_amount' => 800,
            'decision_notes' => 'Decision result is not routing',
            'create_refund' => $refund,
            'continue_to_next_stage' => $continue,
        ];
    }

    public static function recoveryStages(): array
    {
        return [[7], [10], [12]];
    }

    #[\PHPUnit\Framework\Attributes\DataProviderExternal(\Tests\Unit\DecisionPointTaxExposureTest::class, 'matrix')]
    public function test_stage_four_api_matrix(string $spt, string $amount, string $skp, string $assessment, string $expected): void
    {
        $this->case->update(['spt_position_type' => $spt, 'disputed_amount' => $amount]);
        $this->prepareStage(4);
        $this->postJson($this->url(4), ['action' => 'submit', 'skp_type' => $skp,
            'skp_amount' => $assessment, 'skp_number' => 'MATRIX'])->assertOk();
        $this->getJson('/api/tax-cases/'.$this->case->id)->assertOk()
            ->assertJsonPath('data.kian_status_by_stage.4.remainingExposure', $expected)
            ->assertJsonPath('data.kian_status_by_stage.4.needsKian', $expected !== '0.00');
    }

    public function test_partial_stage_seven_document_value_leaves_downstream_exposure_unresolved(): void
    {
        $this->prepareStage(12);
        foreach ([['LB', 1000, 'LB', 750, 100], ['KB', 800, 'KB', 1000, 50]] as [$spt, $amount, $skp, $assessment, $partial]) {
            $this->case->update(['spt_position_type' => $spt, 'disputed_amount' => $amount]);
            $this->case->skpRecord()->update(['skp_type' => $skp, 'skp_amount' => $assessment]);
            $this->case->objectionDecision()->update(['decision_type' => 'partially_granted', 'decision_amount' => $partial]);
            $exposure = $this->case->fresh()->exposureByStage();
            $this->assertSame('unresolved', $exposure[7]['evaluationState']);
            $this->assertStringContainsString('document value, not recovery', $exposure[7]['evaluationReason']);
            $this->assertNull($exposure[10]['remainingExposure']);
        }
        $payload = $this->decisionPayload('FINAL-CHAIN', false, false);
        unset($payload['continue_to_next_stage']);
        $payload['decision_amount'] = 1;
        $this->postJson($this->url(12), $payload)->assertOk();
        $this->assertSame('1.00', $this->case->fresh()->supremeCourtDecision->decision_amount);
        $this->assertNull($this->case->fresh()->exposureByStage()[12]['needsKian']);
    }

    public function test_kian_rows_are_independent_stage_evidence_and_notifications_request_after_commit(): void
    {
        $this->prepareStage(7);
        $payload = $this->decisionPayload('STAGE-EVIDENCE', false, true);
        $payload['decision_type'] = 'rejected';
        $payload['decision_amount'] = 300;
        $this->postJson($this->url(7), $payload)->assertOk();
        foreach ([4 => '1000.00', 7 => '1000.00'] as $stage => $amount) {
            $this->postJson("/api/tax-cases/{$this->case->id}/kian-submissions/{$stage}", [
                'kian_number' => 'KIAN-'.$stage, 'submission_date' => '2026-10-01', 'loss_amount' => $amount,
            ])->assertCreated()->assertJsonPath('data.loss_amount', $amount);
        }
        $this->assertDatabaseCount('kian_submissions', 2);
        Queue::assertPushed(\App\Jobs\SendKianReminderJob::class, fn ($job) => $job->afterCommit === true);
    }

    #[DataProvider('recoveryStages')]
    public function test_rejected_document_value_and_outcome_only_kian_status(int $stage): void
    {
        $this->prepareStage($stage);
        $payload = $this->decisionPayload('REJECTED-'.$stage, false, false);
        unset($payload['continue_to_next_stage']);
        $payload['decision_type'] = 'rejected';
        $payload['decision_amount'] = '2200';
        $this->postJson($this->url($stage), $payload)->assertOk();
        $relation = [7 => 'objectionDecision', 10 => 'appealDecision', 12 => 'supremeCourtDecision'][$stage];
        $this->assertSame('2200.00', $this->case->fresh()->{$relation}->decision_amount);
        $this->getJson('/api/tax-cases/'.$this->case->id)->assertOk()
            ->assertJsonPath("data.kian_status_by_stage.{$stage}.needsKian", true)
            ->assertJsonPath("data.kian_status_by_stage.{$stage}.remainingExposure", '1000.00')
            ->assertJsonPath("data.kian_status_by_stage.{$stage}.lossAmount", null);
        $this->assertDatabaseHas('notification_logs', ['stage_id' => $stage, 'current_status' => 'QUEUED']);
        $this->postJson("/api/tax-cases/{$this->case->id}/kian-submissions/{$stage}", [
            'kian_number' => 'KIAN-'.$stage, 'submission_date' => '2026-10-01', 'kian_amount' => '1.00',
        ])->assertCreated()->assertJsonPath('data.kian_amount', '1.00');
        if ($stage === 12) $this->assertTrue($this->case->fresh()->is_completed);
    }

    public static function invalidRecovery(): array
    {
        $cases = [];
        foreach ([10, 12] as $stage) foreach (['-0.01', '0.001'] as $amount) $cases[] = [$stage, $amount];
        return $cases;
    }

    public static function documentOutcomes(): array
    {
        return [['granted'], ['partially_granted'], ['rejected']];
    }

    #[DataProvider('documentOutcomes')]
    public function test_stage_seven_draft_submit_reload_preserves_document_value(string $outcome): void
    {
        $this->prepareStage(7);
        $this->case->update(['disputed_amount' => '100000', 'reported_amount' => '100000']);
        $this->case->skpRecord()->update(['skp_type' => 'LB', 'skp_amount' => '70000']);
        $this->case->objectionSubmission()->create(['objection_number' => 'OBJ-25000', 'submission_date' => '2026-09-01', 'objection_amount' => '25000']);
        $this->assertSame('30000.00', $this->case->fresh()->exposureByStage()[4]['remainingExposure']);
        $payload = $this->decisionPayload('MANUAL-90000', false, true);
        $payload['decision_type'] = $outcome;
        $payload['decision_amount'] = '90000.12';
        $payload['action'] = 'draft';
        $this->postJson($this->url(7), $payload)->assertOk();
        $this->assertSame('90000.12', $this->case->fresh()->objectionDecision->decision_amount);
        $payload['action'] = 'submit';
        $payload['decision_amount'] = '90000';
        $this->postJson($this->url(7), $payload)->assertOk();
        $this->getJson('/api/tax-cases/'.$this->case->id)->assertOk()
            ->assertJsonPath('data.objection_decision.decision_amount', '90000.00');
        $this->assertSame('25000.00', $this->case->fresh()->objectionSubmission->objection_amount);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertNull($this->case->fresh()->objectionDecision->refund_amount);
        if ($outcome === 'partially_granted') {
            $this->assertNull($this->case->fresh()->exposureByStage()[7]['needsKian']);
            $this->assertDatabaseMissing('notification_logs', ['stage_id' => 7]);
            $this->postJson('/api/tax-cases/'.$this->case->id.'/kian-submissions/7', [])->assertUnprocessable();
        }
        $payload['decision_amount'] = '1';
        $this->postJson($this->url(7), $payload)->assertConflict();
        $this->assertSame('90000.00', $this->case->fresh()->objectionDecision->decision_amount);
    }

    public static function invalidDocumentAmounts(): array
    {
        $cases = [];
        foreach (['draft', 'submit'] as $action) {
            foreach (['-0.01', 'abc', 'NaN', 'INF', '1e5', '0.001', '1.000', true, ['90000']] as $amount) {
                $cases[] = [$action, $amount];
            }
        }
        $cases[] = ['submit', null];
        return $cases;
    }

    #[DataProvider('invalidDocumentAmounts')]
    public function test_stage_seven_invalid_document_amount_has_no_partial_writes(string $action, mixed $amount): void
    {
        $this->prepareStage(7);
        $payload = $this->decisionPayload('INVALID-DOCUMENT', true, true);
        $payload['action'] = $action;
        $payload['decision_amount'] = $amount;
        $this->postJson($this->url(7), $payload)->assertUnprocessable()->assertJsonValidationErrors('decision_amount');
        $this->assertDatabaseCount('objection_decisions', 0);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->assertDatabaseMissing('workflow_histories', ['stage_id' => 7]);
    }

    public function test_dedicated_stage_seven_endpoint_preserves_manual_document_amount(): void
    {
        $this->prepareStage(7);
        $this->case->update(['current_stage' => 7]);
        $payload = $this->decisionPayload('DEDICATED-DOCUMENT', false, true);
        $payload['decision_type'] = 'partially_granted';
        $payload['decision_amount'] = '90000.12';
        $this->postJson('/api/tax-cases/'.$this->case->id.'/objection-decisions', $payload)
            ->assertCreated()->assertJsonPath('data.decision_amount', '90000.12');
        $this->assertDatabaseCount('refund_processes', 0);
    }

    public function test_stage_seven_zero_is_valid_and_prior_exposure_is_not_a_write_dependency(): void
    {
        $this->prepareStage(7);
        $this->case->update(['spt_position_type' => null]);
        $payload = $this->decisionPayload('ZERO-DOCUMENT', false, false);
        $payload['decision_type'] = 'partially_granted';
        $payload['decision_amount'] = '0.00';
        $this->postJson($this->url(7), $payload)->assertOk();
        $this->assertSame('0.00', $this->case->fresh()->objectionDecision->decision_amount);
        $this->assertDatabaseCount('refund_processes', 0);
    }

    #[DataProvider('invalidRecovery')]
    public function test_invalid_partial_has_no_decision_or_reminder_write(int $stage, string $amount): void
    {
        $this->prepareStage($stage);
        $payload = $this->decisionPayload('INVALID', true, false);
        unset($payload['continue_to_next_stage']);
        $payload['decision_type'] = 'partially_granted';
        $payload['decision_amount'] = $amount;
        $this->postJson($this->url($stage), $payload)->assertUnprocessable()->assertJsonValidationErrors('decision_amount');
        $table = [7 => 'objection_decisions', 10 => 'appeal_decisions', 12 => 'supreme_court_decisions'][$stage];
        $this->assertDatabaseCount($table, 0);
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseMissing('workflow_histories', ['stage_id' => $stage, 'status' => 'submitted']);
    }

    public function test_historical_unknown_position_is_explicitly_unresolved_and_preserves_kian(): void
    {
        $this->prepareStage(7);
        $this->case->update(['spt_position_type' => null]);
        $kian = \App\Models\KianSubmission::create(['tax_case_id' => $this->case->id, 'stage_id' => 4,
            'kian_number' => 'HISTORY', 'submission_date' => '2026-09-01', 'kian_amount' => 321, 'loss_amount' => 321, 'status' => 'submitted']);
        $this->getJson('/api/tax-cases/'.$this->case->id)->assertOk()
            ->assertJsonPath('data.kian_status_by_stage.4.evaluationState', 'unresolved')
            ->assertJsonPath('data.kian_status_by_stage.4.needsKian', null)
            ->assertJsonPath('data.kian_status_by_stage.4.kianId', $kian->id);
        $this->postJson("/api/tax-cases/{$this->case->id}/kian-submissions/7", [])->assertUnprocessable();
        app(\App\Services\KianNotificationService::class)->evaluateFinalSubmit($this->case->fresh(), 4);
        $this->assertDatabaseCount('notification_logs', 0);
        $this->getJson("/api/tax-cases/{$this->case->id}/kian-submissions/4")->assertOk()->assertJsonPath('data.kian_amount', '321.00');
        $this->assertNull($this->case->fresh()->spt_position_type);
    }

    public function test_notification_evaluation_is_idempotent_and_status_queries_are_bounded(): void
    {
        $this->prepareStage(7);
        $service = app(\App\Services\KianNotificationService::class);
        $service->evaluateFinalSubmit($this->case->fresh(), 4);
        $service->evaluateFinalSubmit($this->case->fresh(), 4);
        $this->assertDatabaseCount('notification_attempts', 1);
        Queue::assertPushed(\App\Jobs\SendKianReminderJob::class, fn ($job) => $job->afterCommit === true);
        $case = $this->case->fresh();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $case->getKianStatusByStage();
        $first = count(\Illuminate\Support\Facades\DB::getQueryLog());
        $case->getKianStatusByStage();
        $this->assertSame($first, count(\Illuminate\Support\Facades\DB::getQueryLog()));
        // Five fixed case relations, canonical completion history, actor role/entity; loaded once.
        $this->assertLessThanOrEqual(8, $first);
        \Illuminate\Support\Facades\DB::disableQueryLog();
    }

    public function test_zero_exposure_keeps_reminder_ineligible_but_allows_optional_manual_kian(): void
    {
        $this->prepareStage(10);
        $payload = $this->decisionPayload('SKP-KB', false, false);
        $payload['decision_type'] = 'granted';
        $this->postJson($this->url(10), $payload)->assertOk();
        $this->assertSame('0.00', $this->case->fresh()->exposureByStage()[10]['remainingExposure']);
        $this->assertDatabaseHas('notification_logs', ['stage_id' => 10, 'current_status' => 'NOT_ELIGIBLE']);
        $this->postJson("/api/tax-cases/{$this->case->id}/kian-submissions/10", [
            'kian_number' => 'OPTIONAL-ZERO', 'submission_date' => '2026-10-01', 'loss_amount' => '123.45',
        ])->assertCreated()->assertJsonPath('data.kian_amount', '123.45');
    }

    public static function laterDocuments(): array
    {
        $cases = [];
        foreach ([10, 12] as $stage) foreach (['granted', 'partially_granted', 'rejected'] as $outcome) {
            foreach ([false, true] as $dedicated) $cases[] = [$stage, $outcome, $dedicated];
        }
        return $cases;
    }

    #[DataProvider('laterDocuments')]
    public function test_later_document_draft_final_and_reload_are_independent_of_exposure(int $stage, string $outcome, bool $dedicated): void
    {
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        $this->case->objectionDecision()->update(['decision_type' => 'partially_granted', 'decision_amount' => '90000']);
        $relation = $stage === 10 ? 'appealDecision' : 'supremeCourtDecision';
        $payload = $this->decisionPayload('LATER-DOCUMENT', false, false);
        unset($payload['continue_to_next_stage']);
        $payload['decision_type'] = $outcome;
        $payload['decision_amount'] = '90000.12';
        if (!$dedicated) {
            $this->postJson($this->url($stage), ['action' => 'draft'])->assertOk();
            $this->assertNull($this->case->fresh()->{$relation}->decision_amount);
            $this->postJson($this->url($stage), array_replace($payload, ['action' => 'draft']))->assertOk();
            $this->assertSame('90000.12', $this->case->fresh()->{$relation}->decision_amount);
        }
        $url = $dedicated ? '/api/tax-cases/'.$this->case->id.'/'.($stage === 10 ? 'appeal-decisions' : 'supreme-court-decisions') : $this->url($stage);
        $this->postJson($url, $payload)->assertSuccessful();
        $this->assertSame('90000.12', $this->case->fresh()->{$relation}->decision_amount);
        $key = $stage === 10 ? 'appeal_decision' : 'supreme_court_decision';
        $this->getJson('/api/tax-cases/'.$this->case->id)->assertOk()->assertJsonPath("data.{$key}.decision_amount", '90000.12')
            ->assertJsonPath("data.kian_status_by_stage.{$stage}.evaluationState", 'unresolved')
            ->assertJsonPath("data.kian_status_by_stage.{$stage}.remainingExposure", null)
            ->assertJsonPath("data.kian_status_by_stage.{$stage}.needsKian", null);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('kian_submissions', 0);
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->assertNull($this->case->fresh()->{$relation}->refund_amount);
        $this->postJson($url, array_replace($payload, ['decision_amount' => '1']))->assertConflict();
        $this->assertSame('90000.12', $this->case->fresh()->{$relation}->decision_amount);
    }

    public static function invalidLaterDocuments(): array
    {
        $cases = [];
        foreach ([10, 12] as $stage) foreach (['draft', 'submit', 'dedicated'] as $action) {
            foreach (['-1', 'abc', 'NaN', 'Infinity', '1e5', '0.001', '1.000'] as $amount) $cases[] = [$stage, $action, $amount];
            if ($action !== 'draft') $cases[] = [$stage, $action, null];
        }
        return $cases;
    }

    #[DataProvider('invalidLaterDocuments')]
    public function test_later_invalid_money_rolls_back_every_write(int $stage, string $action, mixed $amount): void
    {
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        $payload = $this->decisionPayload('INVALID-LATER', true, false);
        unset($payload['continue_to_next_stage']);
        $payload['decision_amount'] = $amount;
        $payload['action'] = $action === 'draft' ? 'draft' : 'submit';
        $url = $action === 'dedicated' ? '/api/tax-cases/'.$this->case->id.'/'.($stage === 10 ? 'appeal-decisions' : 'supreme-court-decisions') : $this->url($stage);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('decision_amount');
        $this->assertDatabaseCount($stage === 10 ? 'appeal_decisions' : 'supreme_court_decisions', 0);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->assertDatabaseMissing('workflow_histories', ['stage_id' => $stage]);
    }

    public function test_matrix_continues_through_actual_stage_eleven_with_submitted_stage_four_kian(): void
    {
        $this->prepareStage(10);
        $this->case->update(['disputed_amount' => '100000', 'reported_amount' => '100000']);
        $this->case->skpRecord()->update(['skp_type' => 'LB', 'skp_amount' => '70000']);
        $this->case->objectionSubmission()->create(['objection_number' => 'OBJ-25000', 'submission_date' => '2026-09-01', 'objection_amount' => '25000']);
        $this->case->objectionDecision()->update(['decision_type' => 'partially_granted', 'decision_amount' => '90000']);
        \App\Models\KianSubmission::create(['tax_case_id' => $this->case->id, 'stage_id' => 4, 'kian_number' => 'SUBMITTED-4',
            'submission_date' => '2026-09-01', 'kian_amount' => '30000', 'loss_amount' => '30000', 'status' => 'submitted']);
        $this->assertFalse($this->case->fresh()->stageFourKianAccessBlocked());
        $payload = $this->decisionPayload('APPEAL-MATRIX', false, true);
        $payload['decision_type'] = 'partially_granted';
        $payload['decision_amount'] = '95000.12';
        $this->postJson($this->url(10), $payload)->assertOk();
        foreach ([11, 12] as $stage) {
            $document = Document::where('tax_case_id', $this->case->id)->first()->replicate();
            $document->stage_code = (string) $stage;
            $document->save();
        }
        $this->postJson($this->url(11), ['action' => 'submit', 'supreme_court_letter_number' => 'SC-MATRIX',
            'submission_date' => '2026-10-01', 'review_amount' => '20000'])->assertOk();
        unset($payload['continue_to_next_stage']);
        $payload['decision_number'] = 'SUPREME-MATRIX';
        $payload['decision_amount'] = '110000.34';
        $this->postJson($this->url(12), $payload)->assertOk();
        $case = $this->case->fresh();
        $this->assertSame('95000.12', $case->appealDecision->decision_amount);
        $this->assertSame('110000.34', $case->supremeCourtDecision->decision_amount);
        foreach ([7, 10, 12] as $stage) {
            $this->assertSame('unresolved', $case->exposureByStage()[$stage]['evaluationState']);
            $this->assertNull($case->exposureByStage()[$stage]['needsKian']);
        }
        $this->assertSame('30000.00', $case->kianSubmissions->sole()->kian_amount);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('notification_attempts', 0);
    }

    #[DataProvider('recoveryStages')]
    public function test_partial_zero_document_is_valid_but_exposure_stays_unresolved(int $stage): void
    {
        $this->prepareStage($stage);
        $payload = $this->decisionPayload('ZERO-EXACT', false, false);
        unset($payload['continue_to_next_stage']);
        $payload['decision_type'] = 'partially_granted';
        $payload['decision_amount'] = '0.00';
        $this->postJson($this->url($stage), $payload)->assertOk();
        $this->assertSame('unresolved', $this->case->fresh()->exposureByStage()[$stage]['evaluationState']);
        $this->assertNull($this->case->fresh()->exposureByStage()[$stage]['needsKian']);
        $this->assertDatabaseCount('notification_attempts', 0);
    }

    public static function laterStages(): array { return [[10], [12]]; }

    #[DataProvider('laterStages')]
    public function test_later_unresolved_exposure_does_not_bypass_documents_or_stage_order(int $stage): void
    {
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        $this->case->objectionDecision()->update(['decision_type' => 'partially_granted', 'decision_amount' => '90000']);
        $payload = $this->decisionPayload('GUARDED-LATER', false, false);
        unset($payload['continue_to_next_stage']);
        $dedicated = '/api/tax-cases/'.$this->case->id.'/'.($stage === 10 ? 'appeal-decisions' : 'supreme-court-decisions');
        $document = Document::where('tax_case_id', $this->case->id)->sole();
        $document->update(['status' => 'DELETED']);
        foreach ([$this->url($stage), $dedicated] as $url) {
            $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('supporting_docs');
        }
        $document->update(['status' => 'ACTIVE']);
        WorkflowHistory::where('tax_case_id', $this->case->id)->where('stage_id', $stage - 1)->delete();
        foreach ([$this->url($stage), $dedicated] as $url) $this->postJson($url, $payload)->assertConflict();
        $this->assertDatabaseCount($stage === 10 ? 'appeal_decisions' : 'supreme_court_decisions', 0);
        $this->assertDatabaseCount('refund_processes', 0);
    }

    #[DataProvider('laterStages')]
    public function test_later_draft_null_amount_cannot_be_finalized_by_dedicated_approval(int $stage): void
    {
        $this->prepareStage($stage);
        $payload = $this->decisionPayload('NULL-DRAFT', false, false);
        unset($payload['continue_to_next_stage']);
        $payload['action'] = 'draft';
        $payload['decision_amount'] = null;
        $this->postJson($this->url($stage), $payload)->assertOk();
        $relation = $stage === 10 ? 'appealDecision' : 'supremeCourtDecision';
        $target = $this->case->fresh()->{$relation};
        $url = '/api/tax-cases/'.$this->case->id.'/'.($stage === 10 ? 'appeal-decisions' : 'supreme-court-decisions').'/'.$target->id.'/approve';
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('decision_amount');
        $this->assertSame('draft', $target->fresh()->status);
        $this->assertNull($target->fresh()->decision_amount);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('notification_attempts', 0);
    }

    public function test_stage_ten_skp_kb_document_outcome_has_no_invented_exposure(): void
    {
        $this->prepareStage(10);
        $payload = $this->decisionPayload('SKP-KB-DOCUMENT', false, false);
        $payload['decision_type'] = 'skp_kb';
        $payload['decision_amount'] = '90000.12';
        $this->postJson($this->url(10), $payload)->assertOk();
        $this->assertSame('90000.12', $this->case->fresh()->appealDecision->decision_amount);
        $this->assertSame('unresolved', $this->case->fresh()->exposureByStage()[10]['evaluationState']);
        $this->assertNull($this->case->fresh()->exposureByStage()[10]['needsKian']);
        $this->assertDatabaseCount('notification_attempts', 0);
    }

    private function prepareStage(int $stage): void
    {
        if ($stage > 4) \App\Models\SkpRecord::create(['tax_case_id' => $this->case->id, 'skp_number' => 'PRIOR-SKP', 'skp_type' => 'NIHIL', 'skp_amount' => 0]);
        if ($stage > 7) \App\Models\ObjectionDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'PRIOR-OBJ', 'decision_date' => '2026-09-01', 'decision_type' => 'rejected', 'decision_amount' => 0]);
        if ($stage > 10) \App\Models\AppealDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'PRIOR-APL', 'decision_date' => '2026-09-01', 'decision_type' => 'rejected', 'decision_amount' => 0]);

        foreach (range(1, $stage - 1) as $submittedStage) {
            WorkflowHistory::create([
                'tax_case_id' => $this->case->id,
                'stage_id' => $submittedStage,
                'action' => 'submitted',
                'status' => 'submitted',
                'user_id' => $this->user->id,
            ]);
        }

        Document::create([
            'documentable_type' => TaxCase::class,
            'documentable_id' => $this->case->id,
            'tax_case_id' => $this->case->id,
            'document_type' => 'supporting_document',
            'stage_code' => (string) $stage,
            'original_filename' => "phase-1c-{$stage}.pdf",
            'file_path' => "tests/phase-1c-{$stage}.pdf",
            'file_mime_type' => 'application/pdf',
            'file_size' => 1,
            'uploaded_by' => $this->user->id,
            'uploaded_at' => now(),
            'status' => 'ACTIVE',
        ]);
    }

    private function url(int $stage): string
    {
        return "/api/tax-cases/{$this->case->id}/workflow/{$stage}";
    }
}
