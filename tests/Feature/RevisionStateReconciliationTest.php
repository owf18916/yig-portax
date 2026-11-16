<?php

namespace Tests\Feature;

use App\Models\{BankTransferRequest, CaseStatus, Currency, Entity, FiscalYear, ObjectionDecision, RefundProcess, Role, SkpRecord, SupremeCourtDecision, TaxCase, User, WorkflowHistory};
use App\Services\MainWorkflowStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Queue};
use Tests\TestCase;

class RevisionStateReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;
    private User $approver;
    private TaxCase $case;
    private SkpRecord $target;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Mail::fake();
        $affiliate = Entity::create(['code'=>'REV-A','name'=>'Revision Affiliate','entity_type'=>'AFFILIATE','tax_id'=>'REV-A']);
        $holding = Entity::create(['code'=>'REV-H','name'=>'Revision Holding','entity_type'=>'HOLDING','tax_id'=>'REV-H']);
        $role = Role::create(['code'=>'REV-STAFF','name'=>'Staff']);
        $this->requester = User::factory()->create(['entity_id'=>$affiliate->id,'role_id'=>$role->id]);
        $this->approver = User::factory()->create(['entity_id'=>$holding->id,'role_id'=>$role->id]);
        $year = FiscalYear::create(['year'=>2026,'start_date'=>'2026-01-01','end_date'=>'2026-12-31']);
        $currency = Currency::create(['code'=>'REV','name'=>'Revision Currency','symbol'=>'R']);
        $open = CaseStatus::create(['code'=>'OPEN','name'=>'Open']);
        $this->case = TaxCase::create(['user_id'=>$this->requester->id,'entity_id'=>$affiliate->id,'fiscal_year_id'=>$year->id,'currency_id'=>$currency->id,'case_status_id'=>$open->id,'case_number'=>'REV-1','case_type'=>'CIT','spt_position_type'=>'LB','reported_amount'=>1000,'disputed_amount'=>1000,'current_stage'=>4]);
        $this->target = SkpRecord::create(['tax_case_id'=>$this->case->id,'skp_type'=>'LB','skp_number'=>'SKP-OLD','skp_amount'=>800,'create_refund'=>false,'continue_to_next_stage'=>false]);
        foreach (range(1, 4) as $stage) $this->history($stage);
    }

    public function test_server_contract_rejects_control_fields_without_mutation(): void
    {
        $this->actingAs($this->requester)->postJson($this->requestUrl(), ['payload'=>json_encode($this->payload([
            'tax_case_id'=>999, 'current_stage'=>12, 'is_completed'=>true,
        ], ['tax_case_id','current_stage','is_completed']))])->assertUnprocessable();
        $this->assertSame($this->case->id, $this->target->fresh()->tax_case_id);
        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_stage_four_skp_due_date_uses_one_canonical_key_in_both_collections(): void
    {
        $response = $this->request(['skp_due_date' => '2026-10-15'], ['skp_due_date'])
            ->assertCreated()
            ->assertJsonPath('revision.revision_status', 'requested')
            ->assertJsonPath('revision.stage_code', 4)
            ->assertJsonPath('revision.target_id', $this->target->id);

        $this->assertSame(['skp_due_date' => '2026-10-15'], $response->json('revision.proposed_values'));
    }

    public function test_mismatched_revision_field_keys_remain_rejected(): void
    {
        $this->request(['correction_notes' => 'A changed explanatory note'], ['skp_due_date'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');
        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_false_stage_four_value_is_preserved(): void
    {
        $this->request(['continue_to_next_stage' => false], ['continue_to_next_stage'])
            ->assertCreated()
            ->assertJsonPath('revision.proposed_values.continue_to_next_stage', false);
    }

    public function test_zero_stage_four_value_is_preserved(): void
    {
        $this->request(['refund_amount' => 0], ['refund_amount'])
            ->assertCreated()
            ->assertJsonPath('revision.proposed_values.refund_amount', '0.00');
    }

    public function test_nullable_stage_four_value_is_preserved(): void
    {
        $this->request(['skp_due_date' => null], ['skp_due_date'])
            ->assertCreated()
            ->assertJsonPath('revision.proposed_values.skp_due_date', null);
    }

    public function test_exact_stage_evidence_and_per_target_pending_uniqueness_are_enforced(): void
    {
        WorkflowHistory::where('tax_case_id',$this->case->id)->where('stage_id',4)->delete();
        $this->actingAs($this->requester)->postJson($this->requestUrl(), ['payload'=>json_encode($this->payload(['skp_number'=>'SKP-NEW'],['skp_number']))])->assertUnprocessable();
        $this->history(4);
        $this->request(['skp_number'=>'SKP-NEW'], ['skp_number'])->assertCreated();
        $this->request(['skp_number'=>'SKP-OTHER'], ['skp_number'])->assertUnprocessable()->assertJsonValidationErrors('revision');
    }

    public function test_authorized_requester_and_holding_can_view_the_same_requested_revision(): void
    {
        $revision = $this->request(['skp_number' => 'VISIBLE-REVISION'], ['skp_number'])
            ->assertCreated()
            ->json('revision');

        $this->actingAs($this->requester)
            ->getJson("/api/tax-cases/{$this->case->id}/revisions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $revision['id'])
            ->assertJsonPath('data.0.revision_status', 'requested')
            ->assertJsonPath('data.0.stage_code', 4)
            ->assertJsonPath('data.0.target_type', SkpRecord::class)
            ->assertJsonPath('data.0.target_id', $this->target->id)
            ->assertJsonPath('data.0.can_decide', false);

        $this->actingAs($this->approver)
            ->getJson("/api/tax-cases/{$this->case->id}/revisions")
            ->assertOk()
            ->assertJsonPath('data.0.id', $revision['id'])
            ->assertJsonPath('data.0.can_decide', true);
    }

    public function test_affiliate_admin_cannot_decide_and_final_revisions_have_no_decision_capability(): void
    {
        $adminRole = Role::create(['code' => 'REV-ADMIN', 'name' => 'Admin']);
        $affiliateAdmin = User::factory()->create([
            'entity_id' => $this->requester->entity_id,
            'role_id' => $adminRole->id,
        ]);
        $revision = $this->request(['skp_number' => 'ADMIN-BLOCKED'], ['skp_number'])
            ->assertCreated()->json('revision');
        $url = "/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide";

        $this->actingAs($affiliateAdmin)
            ->getJson("/api/tax-cases/{$this->case->id}/revisions")
            ->assertOk()
            ->assertJsonPath('data.0.can_decide', false);
        $this->patchJson($url, ['decision' => 'approve'])->assertForbidden();

        $this->actingAs($this->approver)
            ->patchJson($url, ['decision' => 'reject', 'rejection_reason' => 'Rejected for test'])
            ->assertOk();
        $this->getJson("/api/tax-cases/{$this->case->id}/revisions")
            ->assertOk()
            ->assertJsonPath('data.0.revision_status', 'rejected')
            ->assertJsonPath('data.0.can_decide', false);
    }

    public function test_approval_applies_once_reconciles_routing_refund_and_writes_structured_audit(): void
    {
        $revision = $this->request(['continue_to_next_stage'=>true,'create_refund'=>true,'refund_amount'=>125], ['continue_to_next_stage','create_refund','refund_amount'])->assertCreated()->json('revision');
        $url = "/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide";
        $this->actingAs($this->approver)->patchJson($url,['decision'=>'approve'])->assertOk()->assertJsonPath('revision.revision_status','approved');
        $this->patchJson($url,['decision'=>'approve'])->assertOk();
        $this->assertTrue($this->target->fresh()->continue_to_next_stage);
        $this->assertDatabaseCount('refund_processes',1);
        $this->assertDatabaseCount('notification_logs',1);
        $this->assertDatabaseHas('revisions',['id'=>$revision['id'],'revision_status'=>'approved']);
        $audit = json_decode((string)DB::table('revisions')->where('id',$revision['id'])->value('audit_data'),true);
        $this->assertSame(5,$audit['routing_impact']['next_stage']);
        $this->assertSame('created_idempotently',$audit['refund_reconciliation']);
    }

    public function test_stage_seven_continue_off_to_on_approval_makes_stage_eight_available(): void
    {
        foreach ([5, 6, 7] as $stage) $this->history($stage);
        $decision = ObjectionDecision::create([
            'tax_case_id' => $this->case->id,
            'decision_number' => 'OBJ-OFF',
            'decision_date' => '2026-09-30',
            'decision_type' => 'rejected',
            'decision_amount' => 500,
            'continue_to_next_stage' => false,
            'status' => 'submitted',
        ]);
        $payload = [
            'stage_code' => 7,
            'fields' => ['continue_to_next_stage'],
            'reason' => 'Continue the objection decision to appeal.',
            'proposed_values' => ['continue_to_next_stage' => true],
            'proposed_document_changes' => ['files_to_delete' => [], 'files_to_add' => []],
        ];
        $revision = $this->actingAs($this->requester)
            ->postJson($this->requestUrl(), ['payload' => json_encode($payload)])
            ->assertCreated()
            ->json('revision');

        $this->actingAs($this->approver)
            ->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('revision.revision_status', 'approved');

        $this->assertTrue($decision->fresh()->continue_to_next_stage);
        $stageEight = collect(app(MainWorkflowStateResolver::class)->resolve($this->case->fresh())['stages'])
            ->firstWhere('stage', 8);
        $this->assertSame('available', $stageEight['status']);
    }

    public function test_downstream_and_stale_approvals_are_blocked(): void
    {
        $revision = $this->request(['skp_number'=>'BLOCKED'],['skp_number'])->assertCreated()->json('revision');
        $this->history(5);
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide",['decision'=>'approve'])->assertUnprocessable();
        $this->assertSame('SKP-OLD',$this->target->fresh()->skp_number);

        WorkflowHistory::where('tax_case_id',$this->case->id)->where('stage_id',5)->delete();
        $this->actingAs($this->requester)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide",['decision'=>'reject'])->assertForbidden();
        // Reject with the authorized actor, then create a fresh request for stale checking.
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide",['decision'=>'reject','rejection_reason'=>'replace stale test request'])->assertOk();
        $fresh = $this->actingAs($this->requester)->postJson($this->requestUrl(),['payload'=>json_encode($this->payload(['skp_number'=>'STALE'],['skp_number']))])->assertCreated()->json('revision');
        $this->target->update(['skp_number'=>'LEGITIMATE-NEWER']);
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$fresh['id']}/decide",['decision'=>'approve'])->assertUnprocessable();
        $this->assertSame('LEGITIMATE-NEWER',$this->target->fresh()->skp_number);
    }

    public function test_submit_after_approval_endpoint_is_non_mutating(): void
    {
        $revision = $this->request(['skp_number'=>'APPLIED'],['skp_number'])->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide",['decision'=>'approve'])->assertOk();
        $this->actingAs($this->requester)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/submit",['revised_data'=>['skp_number'=>'TAMPERED']])->assertConflict();
        $this->assertSame('APPLIED',$this->target->fresh()->skp_number);
    }

    public function test_refund_reversal_and_amount_changes_respect_editable_boundary(): void
    {
        $this->target->update(['create_refund'=>true,'refund_amount'=>100]);
        $refund = app(\App\Services\DecisionPointRefundService::class)->createIfRequested($this->target, 4, $this->requester->id);
        $revision = $this->request(['refund_amount'=>150],['refund_amount'])->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide",['decision'=>'approve'])->assertOk();
        $this->assertSame('150.00',$refund->fresh()->refund_amount);

        BankTransferRequest::create(['refund_process_id'=>$refund->id,'request_number'=>'TRANSFER-1','transfer_amount'=>150,'transfer_status'=>'pending','created_by'=>$this->requester->id]);
        $blocked = $this->actingAs($this->requester)->postJson($this->requestUrl(),['payload'=>json_encode($this->payload(['create_refund'=>false],['create_refund']))])->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$blocked['id']}/decide",['decision'=>'approve'])->assertUnprocessable();
        $this->assertTrue($this->target->fresh()->create_refund);
        $this->assertSame('pending',$refund->fresh()->refund_status);
    }

    public function test_initial_refund_can_be_reconciled_to_existing_rejected_state(): void
    {
        $this->target->update(['create_refund'=>true,'refund_amount'=>100]);
        $refund = app(\App\Services\DecisionPointRefundService::class)->createIfRequested($this->target, 4, $this->requester->id);
        $revision = $this->request(['create_refund'=>false],['create_refund'])->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide",['decision'=>'approve'])->assertOk();
        $this->assertFalse($this->target->fresh()->create_refund);
        $this->assertSame('rejected',$refund->fresh()->refund_status);
        $this->assertDatabaseHas('refund_processes',['id'=>$refund->id,'deleted_at'=>null]);
    }

    public function test_terminal_stage_twelve_revision_is_blocked(): void
    {
        foreach (range(5,12) as $stage) $this->history($stage);
        SupremeCourtDecision::create(['tax_case_id'=>$this->case->id,'decision_number'=>'FINAL','decision_amount'=>700,'status'=>'approved']);
        $this->case->update(['is_completed'=>true]);
        $payload = ['stage_code'=>12,'fields'=>['decision_number'],'reason'=>'Attempt to revise terminal decision','proposed_values'=>['decision_number'=>'REOPEN'],'proposed_document_changes'=>['files_to_delete'=>[],'files_to_add'=>[]]];
        $this->actingAs($this->requester)->postJson($this->requestUrl(),['payload'=>json_encode($payload)])->assertUnprocessable()->assertJsonValidationErrors('stage_code');
        $this->assertDatabaseMissing('revisions',['stage_code'=>12]);
    }

    private function request(array $values, array $fields)
    {
        return $this->actingAs($this->requester)->postJson($this->requestUrl(), ['payload'=>json_encode($this->payload($values,$fields))]);
    }

    public function test_approved_stage_seven_revision_preserves_document_value_and_prior_evidence(): void
    {
        foreach ([5, 6, 7] as $stage) $this->history($stage);
        $decision = ObjectionDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'REV-RECOVERY',
            'decision_date' => '2026-09-30', 'decision_type' => 'rejected', 'decision_amount' => 500, 'status' => 'submitted']);
        $service = app(\App\Services\KianNotificationService::class);
        $service->evaluateFinalSubmit($this->case->fresh(), 7);
        $priorLog = \App\Models\NotificationLog::sole();
        $payload = ['stage_code' => 7, 'fields' => ['decision_type', 'decision_amount'], 'reason' => 'Correct the recorded document value after review.',
            'proposed_values' => ['decision_type' => 'granted', 'decision_amount' => 1], 'proposed_document_changes' => []];
        $revision = $this->actingAs($this->requester)->postJson($this->requestUrl(), ['payload' => json_encode($payload)])
            ->assertCreated()->json('revision');
        $url = "/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide";
        $this->actingAs($this->approver)->patchJson($url, ['decision' => 'approve'])->assertOk();
        $this->patchJson($url, ['decision' => 'approve'])->assertOk();
        $this->assertSame('1.00', $decision->fresh()->decision_amount);
        $this->assertFalse($this->case->fresh()->needsKianAtStage(7));
        $this->assertSame('QUEUED', $priorLog->fresh()->current_status);
        $this->assertDatabaseCount('notification_attempts', 1);
        $audit = \App\Models\Revision::findOrFail($revision['id'])->audit_data;
        // Raw audit decimals vary by driver; compare exact cents without rounding.
        $this->assertSame('500.00', (string) \Brick\Math\BigDecimal::of($audit['before']['decision_amount'])->toScale(2));
        $this->assertSame('1.00', (string) \Brick\Math\BigDecimal::of($audit['after']['decision_amount'])->toScale(2));
    }

    public function test_invalid_partial_revision_does_not_apply_or_queue_a_notification(): void
    {
        foreach ([5, 6, 7] as $stage) $this->history($stage);
        $decision = ObjectionDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'REV-INVALID',
            'decision_date' => '2026-09-30', 'decision_type' => 'rejected', 'decision_amount' => 0, 'status' => 'submitted']);
        $payload = ['stage_code' => 7, 'fields' => ['decision_type', 'decision_amount'], 'reason' => 'Invalid partial must remain unapplied.',
            'proposed_values' => ['decision_type' => 'partially_granted', 'decision_amount' => '200.001'], 'proposed_document_changes' => []];
        $revision = $this->actingAs($this->requester)->postJson($this->requestUrl(), ['payload' => json_encode($payload)])
            ->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision' => 'approve'])->assertUnprocessable();
        $this->assertSame('rejected', $decision->fresh()->decision_type);
        $this->assertDatabaseHas('revisions', ['id' => $revision['id'], 'revision_status' => 'requested', 'audit_data' => null]);
        $this->assertDatabaseCount('notification_attempts', 0);
    }

    public static function appealDocumentRevisions(): array
    {
        return [
            ['granted', '90000.12', true], ['rejected', '90000.12', true],
            ['partially_granted', '0.00', true], ['partially_granted', '90000.12', true],
            ['partially_granted', '1.000', false], ['granted', '1e5', false],
            ['rejected', '-1', false], ['granted', 'abc', false], ['rejected', 'NaN', false], ['granted', 'Infinity', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('appealDocumentRevisions')]
    public function test_appeal_revision_preserves_document_value_with_unresolved_prior_exposure(string $outcome, string $amount, bool $valid): void
    {
        foreach (range(5, 10) as $stage) $this->history($stage);
        ObjectionDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'PARTIAL-PRIOR', 'decision_date' => '2026-09-01',
            'decision_type' => 'partially_granted', 'decision_amount' => '90000', 'status' => 'submitted']);
        $target = \App\Models\AppealDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'REV-APPEAL',
            'decision_date' => '2026-09-30', 'decision_type' => 'rejected', 'decision_amount' => '123.45', 'status' => 'submitted']);
        $payload = ['stage_code' => 10, 'fields' => ['decision_type', 'decision_amount'], 'reason' => 'Correct the exact amount stated in the appeal document.',
            'proposed_values' => ['decision_type' => $outcome, 'decision_amount' => $amount], 'proposed_document_changes' => []];
        $request = $this->actingAs($this->requester)->postJson($this->requestUrl(), ['payload' => json_encode($payload)]);
        if (in_array($amount, ['-1', 'abc', 'NaN', 'Infinity'], true)) {
            $request->assertUnprocessable();
            $this->assertSame('123.45', $target->fresh()->decision_amount);
            $this->assertDatabaseCount('revisions', 0);
            $this->assertDatabaseCount('notification_attempts', 0);
            return;
        }
        $revision = $request->assertCreated()->json('revision');
        $response = $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision' => 'approve']);
        if ($valid) {
            $response->assertOk();
            $this->assertSame($amount, $target->fresh()->decision_amount);
            $this->assertDatabaseHas('revisions', ['id' => $revision['id'], 'revision_status' => 'approved']);
        } else {
            $response->assertUnprocessable();
            $this->assertSame('123.45', $target->fresh()->decision_amount);
            $this->assertSame('rejected', $target->fresh()->decision_type);
            $this->assertDatabaseHas('revisions', ['id' => $revision['id'], 'revision_status' => 'requested', 'audit_data' => null]);
        }
        $this->assertNull($this->case->fresh()->exposureByStage()[10]['needsKian']);
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('kian_submissions', 0);
    }

    public function test_appeal_document_revision_still_blocks_downstream_submitted_evidence(): void
    {
        foreach (range(5, 10) as $stage) $this->history($stage);
        ObjectionDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'PARTIAL-PRIOR', 'decision_date' => '2026-09-01',
            'decision_type' => 'partially_granted', 'decision_amount' => '90000', 'status' => 'submitted']);
        $target = \App\Models\AppealDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'REV-APPEAL', 'decision_date' => '2026-09-30',
            'decision_type' => 'rejected', 'decision_amount' => '123.45', 'status' => 'submitted']);
        $payload = ['stage_code' => 10, 'fields' => ['decision_amount'], 'reason' => 'Attempt amount correction after downstream submission.',
            'proposed_values' => ['decision_amount' => '90000.12'], 'proposed_document_changes' => []];
        $revision = $this->actingAs($this->requester)->postJson($this->requestUrl(), ['payload' => json_encode($payload)])
            ->assertCreated()->json('revision');
        $this->history(11);
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision' => 'approve'])->assertUnprocessable();
        $this->assertSame('123.45', $target->fresh()->decision_amount);
    }

    public static function invalidRefundRevisionAmounts(): array
    {
        return array_map(fn ($v) => [$v], ['0', '-1', null, '1e3', '1.001', '1000000000000000000.00', 1.25]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRefundRevisionAmounts')]
    public function test_invalid_explicit_refund_change_is_rejected_before_request(mixed $amount): void
    {
        $this->target->update(['create_refund'=>true, 'refund_amount'=>'100.00']);
        $refund = app(\App\Services\DecisionPointRefundService::class)->createIfRequested($this->target, 4, $this->requester->id);
        $before = $refund->getAttributes();
        $this->request(['refund_amount'=>$amount], ['refund_amount'])->assertUnprocessable();
        $this->assertSame($before, $refund->fresh()->getAttributes());
        $this->assertSame('100.00', $this->target->fresh()->refund_amount);
        $this->assertDatabaseCount('revisions', 0);
    }

    public static function refundAnomalies(): array
    {
        return array_map(fn ($v) => [$v], ['zero', 'mismatch', 'source', 'pointer', 'method', 'deleted', 'duplicate', 'deleted-transfer', 'duplicate-transfer', 'malformed-transfer', 'adjusted-transfer']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refundAnomalies')]
    public function test_unrelated_revision_never_repairs_anomalies_and_explicit_change_fails_closed(string $anomaly): void
    {
        $this->target->update(['create_refund'=>true, 'refund_amount'=>'100.00']);
        $refund = app(\App\Services\DecisionPointRefundService::class)->createIfRequested($this->target, 4, $this->requester->id);
        if ($anomaly === 'zero') $refund->update(['refund_amount'=>'0.00']);
        if ($anomaly === 'mismatch') $refund->update(['refund_amount'=>'90.00']);
        if ($anomaly === 'source') $refund->update(['stage_source'=>'APPEAL']);
        if ($anomaly === 'pointer') $refund->update(['triggered_by_decision_id'=>999999]);
        if ($anomaly === 'method') $refund->update(['refund_method'=>'cash']);
        if ($anomaly === 'deleted') $refund->delete();
        if ($anomaly === 'duplicate') {
            $row = $refund->getAttributes(); unset($row['id']);
            $row['refund_number'] = 'DUPLICATE'; $row['deleted_at'] = now();
            DB::table('refund_processes')->insert($row);
        }
        if (str_contains($anomaly, 'transfer')) {
            $refund->update(['refund_status'=>'approved']);
            $transfer = BankTransferRequest::create(['refund_process_id'=>$refund->id, 'transfer_amount'=>$anomaly === 'adjusted-transfer' ? '90.00' : '100.00', 'request_number'=>'REQ', 'request_date'=>'2026-11-01', 'transfer_date'=>'2026-11-02', 'transfer_status'=>'pending', 'created_by'=>$this->requester->id]);
            if ($anomaly === 'deleted-transfer') $transfer->delete();
            if ($anomaly === 'duplicate-transfer') $transfer->replicate()->save();
            if ($anomaly === 'malformed-transfer') $transfer->update(['request_number'=>'']);
        }
        $before = RefundProcess::withTrashed()->orderBy('id')->get()->toArray();
        $payments = BankTransferRequest::withTrashed()->orderBy('id')->get()->toArray();
        $note = $this->request(['correction_notes'=>'Documentary note only.'], ['correction_notes'])->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$note['id']}/decide", ['decision'=>'approve'])->assertOk();
        $revision = $this->request(['refund_amount'=>'80.00'], ['refund_amount'])->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision'=>'approve'])->assertUnprocessable();
        $this->assertSame($before, RefundProcess::withTrashed()->orderBy('id')->get()->toArray());
        $this->assertSame($payments, BankTransferRequest::withTrashed()->orderBy('id')->get()->toArray());
        $this->assertSame('100.00', $this->target->fresh()->refund_amount);
    }

    public static function kianDocumentOwners(): array
    {
        return [[0, 4], [1, 4], [0, 7], [1, 7], [0, 10], [1, 10]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kianDocumentOwners')]
    public function test_main_revision_cannot_delete_or_reassign_kian_supporting_documents(?int $owner, int $origin): void
    {
        if ($origin !== 4) {
            $type = $origin === 7 ? ObjectionDecision::class : \App\Models\AppealDecision::class;
            $type::create(['tax_case_id'=>$this->case->id, 'decision_number'=>'G1-DOC', 'decision_date'=>'2026-11-01', 'decision_amount'=>'1.00', 'decision_type'=>'rejected', 'status'=>'submitted']);
            $this->history($origin);
        }
        \Illuminate\Support\Facades\Storage::fake('local');
        $path = 'synthetic/signed.pdf';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, '%PDF synthetic signed evidence');
        $kian = \App\Models\KianSubmission::create(['tax_case_id'=>$this->case->id, 'stage_id'=>$origin, 'status'=>'submitted', 'kian_number'=>'G1-K', 'submission_date'=>'2026-11-01', 'kian_amount'=>'0.00']);
        $document = \App\Models\Document::create(['tax_case_id'=>$this->case->id, 'stage_code'=>(string)$origin, 'documentable_type'=>\App\Models\KianSubmission::class, 'documentable_id'=>$owner === 1 ? $kian->id : $owner, 'document_type'=>'supporting_document', 'file_path'=>$path, 'original_filename'=>'signed.pdf', 'file_mime_type'=>'application/pdf', 'file_size'=>30, 'uploaded_at'=>now(), 'uploaded_by'=>$this->requester->id]);
        $before = $document->fresh()->getAttributes();
        foreach (['files_to_delete', 'files_to_add'] as $operation) {
            $payload = $this->payload([], ['supporting_docs']); $payload['stage_code'] = $origin; $payload['proposed_document_changes'][$operation] = [$document->id];
            $this->actingAs($this->requester)->postJson($this->requestUrl(), $payload)->assertNotFound();
        }
        $this->assertSame($before, $document->fresh()->getAttributes());
        $this->assertSame('%PDF synthetic signed evidence', \Illuminate\Support\Facades\Storage::disk('local')->get($path));
        $this->assertSame('submitted', $kian->fresh()->status);
        $this->assertDatabaseCount('revisions', 0);
    }

    public function test_approval_rechecks_document_ownership_and_rolls_back_all_business_changes(): void
    {
        $this->target->update(['create_refund'=>true, 'refund_amount'=>'100.00']);
        $refund = app(\App\Services\DecisionPointRefundService::class)->createIfRequested($this->target, 4, $this->requester->id);
        $refundBefore = $refund->fresh()->getAttributes();
        $document = \App\Models\Document::create(['tax_case_id'=>$this->case->id, 'stage_code'=>'4', 'documentable_type'=>SkpRecord::class, 'documentable_id'=>$this->target->id, 'document_type'=>'supporting_document', 'file_path'=>'synthetic/main.pdf', 'original_filename'=>'main.pdf', 'file_mime_type'=>'application/pdf', 'file_size'=>30, 'uploaded_at'=>now(), 'uploaded_by'=>$this->requester->id]);
        $payload = $this->payload(['skp_number'=>'CHANGED', 'refund_amount'=>'80.00'], ['skp_number', 'refund_amount', 'supporting_docs']);
        $payload['proposed_document_changes']['files_to_delete'] = [$document->id];
        $revision = $this->actingAs($this->requester)->postJson($this->requestUrl(), $payload)->assertCreated()->json('revision');
        // A canonical signed-document association becomes Submitted while the proposal waits.
        $kian = \App\Models\KianSubmission::create(['tax_case_id'=>$this->case->id, 'stage_id'=>4, 'status'=>'draft', 'kian_number'=>'RACE', 'submission_date'=>'2026-11-01', 'kian_amount'=>'0.00', 'notes'=>'Synthetic draft for ownership race']);
        $document->update(['documentable_type'=>\App\Models\KianSubmission::class, 'documentable_id'=>0]);
        $kian->update(['status'=>'submitted']);
        $before = $document->fresh()->getAttributes();
        $url = "/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide";
        $this->actingAs($this->approver)->patchJson($url, ['decision'=>'approve'])->assertUnprocessable();
        $this->assertSame('SKP-OLD', $this->target->fresh()->skp_number);
        $this->assertSame('100.00', $this->target->fresh()->refund_amount);
        $this->assertSame($refundBefore, $refund->fresh()->getAttributes());
        $this->assertSame($before, $document->fresh()->getAttributes());
        $this->assertDatabaseHas('revisions', ['id'=>$revision['id'], 'revision_status'=>'requested']);
    }

    public function test_legitimate_main_document_can_be_attached_and_deleted(): void
    {
        $document = \App\Models\Document::create(['tax_case_id'=>$this->case->id, 'stage_code'=>'4', 'documentable_type'=>\App\Models\WorkflowHistory::class, 'documentable_id'=>4, 'document_type'=>'supporting_document', 'file_path'=>'synthetic/main.pdf', 'original_filename'=>'main.pdf', 'file_mime_type'=>'application/pdf', 'file_size'=>30, 'uploaded_at'=>now(), 'uploaded_by'=>$this->requester->id]);
        foreach (['files_to_add', 'files_to_delete'] as $operation) {
            $payload = $this->payload([], ['supporting_docs']); $payload['proposed_document_changes'][$operation] = [$document->id];
            $revision = $this->actingAs($this->requester)->postJson($this->requestUrl(), $payload)->assertCreated()->json('revision');
            $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision'=>'approve'])->assertOk();
        }
        $this->assertTrue($document->fresh()->trashed());
    }

    public static function scopedDocumentFailures(): array
    {
        return array_map(fn ($v) => [$v], ['foreign-case', 'foreign-stage', 'wrong-owner', 'deleted', 'retired-response', 'legacy-response-link']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scopedDocumentFailures')]
    public function test_forged_document_scope_and_retired_links_are_denied_at_both_boundaries(string $shape): void
    {
        $document = \App\Models\Document::create(['tax_case_id'=>$this->case->id, 'stage_code'=>'4', 'documentable_type'=>SkpRecord::class, 'documentable_id'=>$this->target->id, 'document_type'=>'supporting_document', 'file_path'=>'synthetic/main.pdf', 'original_filename'=>'main.pdf', 'uploaded_at'=>now(), 'uploaded_by'=>$this->requester->id]);
        if ($shape === 'foreign-case') {
            $other = $this->case->replicate(); $other->case_number = 'G1-FOREIGN'; $other->save();
            $document->update(['tax_case_id'=>$other->id]);
        }
        if ($shape === 'foreign-stage') $document->update(['stage_code'=>'7']);
        if ($shape === 'wrong-owner') $document->update(['documentable_id'=>999999]);
        if ($shape === 'deleted') $document->delete();
        if ($shape === 'retired-response') $document->update(['document_type'=>'kian_final_response']);
        if ($shape === 'legacy-response-link') {
            $kian = \App\Models\KianSubmission::create(['tax_case_id'=>$this->case->id, 'stage_id'=>4, 'kian_number'=>'G1-LEGACY', 'submission_date'=>'2026-11-01', 'kian_amount'=>'0.00', 'status'=>'submitted']);
            DB::table('kian_submissions')->where('id', $kian->id)->update(['response_document_id'=>$document->id]);
        }
        $payload = $this->payload(['skp_number'=>'FORGED'], ['skp_number', 'supporting_docs']);
        $payload['proposed_document_changes']['files_to_delete'] = [$document->id];
        $before = \App\Models\Document::withTrashed()->findOrFail($document->id)->getAttributes();
        $this->actingAs($this->requester)->postJson($this->requestUrl(), $payload)->assertNotFound();
        // Stored proposals from older code are subject to the same approval-time check.
        $revision = $this->request(['skp_number'=>'FORGED'], ['skp_number'])->assertCreated()->json('revision');
        \App\Models\Revision::findOrFail($revision['id'])->update(['proposed_document_changes'=>$payload['proposed_document_changes']]);
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision'=>'approve'])->assertUnprocessable();
        $this->assertSame($before, \App\Models\Document::withTrashed()->findOrFail($document->id)->getAttributes());
        $this->assertSame('SKP-OLD', $this->target->fresh()->skp_number);
    }

    public function test_note_only_matching_refund_and_rejected_amount_proposal_do_not_write_financial_records(): void
    {
        $this->target->update(['create_refund'=>true, 'refund_amount'=>'100.00']);
        $refund = app(\App\Services\DecisionPointRefundService::class)->createIfRequested($this->target, 4, $this->requester->id);
        $before = $refund->fresh()->getAttributes();
        $revision = $this->request(['correction_notes'=>'A note without monetary intent.'], ['correction_notes'])->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision'=>'approve'])->assertOk();
        $revision = $this->request(['refund_amount'=>'80.00'], ['refund_amount'])->assertCreated()->json('revision');
        $this->actingAs($this->approver)->patchJson("/api/tax-cases/{$this->case->id}/revisions/{$revision['id']}/decide", ['decision'=>'reject', 'rejection_reason'=>'Retain the original authority.'])->assertOk();
        $this->assertSame($before, $refund->fresh()->getAttributes());
        $this->assertSame('100.00', $this->target->fresh()->refund_amount);
    }

    private function requestUrl(): string { return "/api/tax-cases/{$this->case->id}/revisions/request"; }
    private function payload(array $values, array $fields): array { return ['stage_code'=>4,'fields'=>$fields,'reason'=>'A sufficiently detailed revision reason','proposed_values'=>$values,'proposed_document_changes'=>['files_to_delete'=>[],'files_to_add'=>[]]]; }
    private function history(int $stage): void { WorkflowHistory::create(['tax_case_id'=>$this->case->id,'stage_id'=>$stage,'stage_from'=>$stage,'stage_to'=>$stage<12?$stage+1:null,'action'=>'submitted','status'=>'submitted','user_id'=>$this->requester->id]); }
}
