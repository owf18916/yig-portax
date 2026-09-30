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
        $affiliate = Entity::create(['code'=>'REV-A','name'=>'Revision Affiliate','entity_type'=>'AFFILIATE','tax_id'=>'REV-A']);
        $holding = Entity::create(['code'=>'REV-H','name'=>'Revision Holding','entity_type'=>'HOLDING','tax_id'=>'REV-H']);
        $role = Role::create(['code'=>'REV-STAFF','name'=>'Staff']);
        $this->requester = User::factory()->create(['entity_id'=>$affiliate->id,'role_id'=>$role->id]);
        $this->approver = User::factory()->create(['entity_id'=>$holding->id,'role_id'=>$role->id]);
        $year = FiscalYear::create(['year'=>2026,'start_date'=>'2026-01-01','end_date'=>'2026-12-31']);
        $currency = Currency::create(['code'=>'REV','name'=>'Revision Currency','symbol'=>'R']);
        $open = CaseStatus::create(['code'=>'OPEN','name'=>'Open']);
        $this->case = TaxCase::create(['user_id'=>$this->requester->id,'entity_id'=>$affiliate->id,'fiscal_year_id'=>$year->id,'currency_id'=>$currency->id,'case_status_id'=>$open->id,'case_number'=>'REV-1','case_type'=>'CIT','reported_amount'=>1000,'disputed_amount'=>1000,'current_stage'=>4]);
        $this->target = SkpRecord::create(['tax_case_id'=>$this->case->id,'skp_number'=>'SKP-OLD','skp_amount'=>800,'create_refund'=>false,'continue_to_next_stage'=>false]);
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
            ->assertJsonPath('revision.proposed_values.refund_amount', 0);
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
        $refund = RefundProcess::create(['tax_case_id'=>$this->case->id,'stage_id'=>4,'refund_number'=>'REV-REF','refund_amount'=>100,'refund_method'=>'bank_transfer','refund_status'=>'pending','status'=>'draft','sequence_number'=>1,'submitted_by'=>$this->requester->id]);
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
        $refund = RefundProcess::create(['tax_case_id'=>$this->case->id,'stage_id'=>4,'refund_number'=>'REV-SAFE','refund_amount'=>100,'refund_method'=>'bank_transfer','refund_status'=>'pending','status'=>'draft','sequence_number'=>1,'submitted_by'=>$this->requester->id]);
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

    private function requestUrl(): string { return "/api/tax-cases/{$this->case->id}/revisions/request"; }
    private function payload(array $values, array $fields): array { return ['stage_code'=>4,'fields'=>$fields,'reason'=>'A sufficiently detailed revision reason','proposed_values'=>$values,'proposed_document_changes'=>['files_to_delete'=>[],'files_to_add'=>[]]]; }
    private function history(int $stage): void { WorkflowHistory::create(['tax_case_id'=>$this->case->id,'stage_id'=>$stage,'stage_from'=>$stage,'stage_to'=>$stage<12?$stage+1:null,'action'=>'submitted','status'=>'submitted','user_id'=>$this->requester->id]); }
}
