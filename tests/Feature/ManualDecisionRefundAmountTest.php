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
use App\Models\TaxCase;
use App\Models\User;
use App\Models\WorkflowHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualDecisionRefundAmountTest extends TestCase
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

        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Mail::fake();
        $this->actingAs($this->user);
    }


    private function payload(int $stage): array
    {
        $data = $stage === 4
            ? ['skp_number' => 'MANUAL-SKP', 'issue_date' => '2026-11-13', 'skp_type' => 'LB', 'skp_amount' => '800.00']
            : ['decision_number' => 'MANUAL-'.$stage, 'decision_date' => '2026-11-13', 'decision_type' => 'partially_granted', 'decision_amount' => '90000.12'];
        return $data + ['action' => 'submit', 'create_refund' => true, 'refund_amount' => '50000.13'];
    }

    private function endpoint(int $stage, bool $dedicated): string
    {
        return $dedicated ? '/api/tax-cases/'.$this->case->id.'/'.[4 => 'skp-records', 7 => 'objection-decisions', 10 => 'appeal-decisions', 12 => 'supreme-court-decisions'][$stage] : $this->url($stage);
    }

    private function relation(int $stage): string
    {
        return [4 => 'skpRecord', 7 => 'objectionDecision', 10 => 'appealDecision', 12 => 'supremeCourtDecision'][$stage];
    }

    public static function endpoints(): array
    {
        $cases = [];
        foreach ([4, 7, 10, 12] as $stage) foreach ([false, true] as $dedicated) $cases[] = [$stage, $dedicated];
        return $cases;
    }

    public static function intents(): array
    {
        $cases = [];
        foreach (self::endpoints() as [$stage, $dedicated]) foreach ([false, true] as $continue) {
            if ($stage !== 12 || ! $continue) $cases[] = [$stage, $dedicated, $continue];
        }
        return $cases;
    }

    #[DataProvider('intents')]
    public function test_manual_amount_is_independent_and_selected_id_becomes_payable(int $stage, bool $dedicated, bool $continue): void
    {
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        $this->case->currency->update(['code' => $dedicated ? 'USD' : 'IDR']);
        $payload = $this->payload($stage);
        if ($stage !== 12) $payload['continue_to_next_stage'] = $continue;
        $this->postJson($this->endpoint($stage, $dedicated), $payload)->assertSuccessful();
        $decision = $this->case->fresh()->{$this->relation($stage)};
        $refund = RefundProcess::sole();
        $this->assertSame('50000.13', $decision->refund_amount);
        $this->assertSame($decision->refund_amount, $refund->refund_amount);
        $this->assertSame($decision::class, $refund->triggered_by_decision_type);
        $this->assertSame($decision->id, $refund->triggered_by_decision_id);
        $this->assertSame($stage, $refund->stage_id);
        $this->assertSame([4 => 'SKP', 7 => 'OBJECTION', 10 => 'APPEAL', 12 => 'SUPREME_COURT'][$stage], $refund->stage_source);
        $this->assertSame($refund->id, app(\App\Services\DecisionPointRefundService::class)->createIfRequested($decision, $stage, $this->user->id)->id);
        $base = '/api/tax-cases/'.$this->case->id.'/refunds/'.$refund->id.'/refund-stages/';
        $this->getJson($base.'1')->assertOk()->assertJsonPath('data.id', $refund->id)->assertJsonPath('data.refund_amount', '50000.13')->assertJsonPath('data.currency_code', $dedicated ? 'USD' : 'IDR');
        $this->getJson($base.'2')->assertOk()->assertJsonPath('data.can_submit', $stage !== 4);
        if ($stage === 4) {
            $this->assertTrue($this->case->fresh()->stageFourKianAccessBlocked());
            \App\Models\KianSubmission::create(['tax_case_id' => $this->case->id, 'stage_id' => 4, 'status' => 'submitted', 'kian_number' => 'SYNTHETIC-K', 'submission_date' => '2026-11-13', 'kian_amount' => '17.99']);
            $this->getJson($base.'2')->assertOk()->assertJsonPath('data.can_submit', true);
        }
        if ($stage === 12) {
            $this->assertSame(12, $this->case->fresh()->current_stage);
            $this->assertTrue($this->case->fresh()->is_completed);
        }
        $this->postJson($this->url($stage), array_replace($payload, ['refund_amount' => '1.00']))->assertConflict();
        $this->assertSame('50000.13', $decision->fresh()->refund_amount);
        $this->assertDatabaseCount('refund_processes', 1);
        $this->assertDatabaseCount('bank_transfer_requests', 0);
        $caseBeforePayment = $this->case->fresh()->getAttributes();
        foreach ([
            2 => ['request_number' => 'MANUAL-REQ', 'request_date' => '2026-11-13', 'transfer_date' => '2026-11-13'],
            3 => ['instruction_number' => 'MANUAL-INS', 'instruction_issue_date' => '2026-11-13', 'instruction_received_date' => '2026-11-13', 'transfer_amount' => '50000.13'],
            4 => ['receipt_number' => 'MANUAL-RCPT', 'received_date' => '2026-11-13', 'received_amount' => '50000.13'],
        ] as $paymentStage => $payment) {
            $this->getJson($base.$paymentStage)->assertOk()->assertJsonPath('data.can_submit', true);
            $this->postJson($base.$paymentStage, $payment)->assertOk();
            $this->postJson($base.$paymentStage, $payment)->assertOk();
        }
        $this->assertSame('completed', $refund->fresh()->refund_status);
        $this->assertSame('50000.13', BankTransferRequest::sole()->received_amount);
        $this->assertSame($refund->id, BankTransferRequest::sole()->refund_process_id);
        $this->assertSame($caseBeforePayment, $this->case->fresh()->getAttributes());
    }

    #[DataProvider('endpoints')]
    public function test_invalid_final_amount_has_no_partial_writes(int $stage, bool $dedicated): void
    {
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        $before = $this->snapshot();
        foreach ([null, '', '0', '0.00', '-1', '0.001', '1.000', '1e5', 'NaN', 'Infinity', 'bad', '1000000000000000000', '999999999999999999.999', 12.34, [], true] as $invalid) {
            $payload = array_replace($this->payload($stage), ['refund_amount' => $invalid]);
            $this->postJson($this->endpoint($stage, $dedicated), $payload)->assertUnprocessable()->assertJsonValidationErrors('refund_amount');
            $this->assertSame($before, $this->snapshot());
        }
    }

    private function snapshot(): array
    {
        $tables = ['skp_records', 'objection_decisions', 'appeal_decisions', 'supreme_court_decisions', 'refund_processes', 'workflow_histories', 'bank_transfer_requests', 'notification_logs', 'notification_attempts'];
        $state = [];
        foreach ($tables as $table) $state[$table] = \Illuminate\Support\Facades\DB::table($table)->orderBy('id')->get()->toJson();
        $state['case'] = $this->case->fresh()->getAttributes();
        return $state;
    }

    #[DataProvider('endpoints')]
    public function test_no_refund_final_needs_no_amount_and_dedicated_draft_cannot_finalize(int $stage, bool $dedicated): void
    {
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        $payload = array_replace($this->payload($stage), ['create_refund' => false]);
        unset($payload['refund_amount']);
        if ($dedicated) {
            $before = $this->snapshot();
            $this->postJson($this->endpoint($stage, true), array_replace($payload, ['action' => 'draft']))->assertUnprocessable()->assertJsonValidationErrors('action');
            $this->assertSame($before, $this->snapshot());
        }
        $this->postJson($this->endpoint($stage, $dedicated), $payload)->assertSuccessful();
        $this->assertDatabaseCount('refund_processes', 0);
    }

    public static function origins(): array { return [[4], [7], [10], [12]]; }

    #[DataProvider('origins')]
    public function test_draft_blank_valid_and_hidden_amount_persist_without_refund(int $stage): void
    {
        $this->prepareStage($stage);
        $payload = array_replace($this->payload($stage), ['action' => 'draft', 'refund_amount' => '']);
        $this->postJson($this->url($stage), $payload)->assertOk();
        $this->assertNull($this->case->fresh()->{$this->relation($stage)}->refund_amount);
        foreach ([true, false, true] as $intent) {
            $payload['create_refund'] = $intent;
            $payload['refund_amount'] = '56789.12';
            $this->postJson($this->url($stage), $payload)->assertOk();
            $this->assertSame('56789.12', $this->case->fresh()->{$this->relation($stage)}->refund_amount);
            $this->assertDatabaseCount('refund_processes', 0);
        }
        $before = $this->snapshot();
        $this->postJson($this->url($stage), array_replace($payload, ['refund_amount' => '1e3']))->assertUnprocessable()->assertJsonValidationErrors('refund_amount');
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('endpoints')]
    public function test_existing_conflict_rolls_back_new_decision_without_replacement(int $stage, bool $dedicated): void
    {
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        RefundProcess::create(['tax_case_id' => $this->case->id, 'stage_id' => $stage, 'stage_source' => [4 => 'SKP', 7 => 'OBJECTION', 10 => 'APPEAL', 12 => 'SUPREME_COURT'][$stage], 'refund_number' => 'LEGACY', 'refund_amount' => '0', 'refund_method' => 'bank_transfer', 'refund_status' => 'pending', 'status' => 'draft']);
        $before = $this->snapshot();
        $this->postJson($this->endpoint($stage, $dedicated), $this->payload($stage))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_decimal_boundary_keeps_maximum_lossless_without_database_float_affinity(): void
    {
        $service = app(\App\Services\DecisionPointRefundService::class);
        $this->assertSame('999999999999999999.99', $service->amount('999999999999999999.99', true));
        $this->assertSame('9007199254740993.12', $service->normalizeInput(['create_refund' => true, 'refund_amount' => '9007199254740993.12'], false)['refund_amount']);
        $this->assertSame('123.00', $service->amount(123, true));
    }


    #[DataProvider('endpoints')]
    public function test_maximum_decimal_through_endpoint_with_test_only_sqlite_text_affinity(int $stage, bool $dedicated): void
    {
        // SQLite NUMERIC affinity uses binary floats. Only this synthetic test uses
        // TEXT columns to exercise the actual application path losslessly. This
        // is not evidence of PostgreSQL decimal storage or locking acceptance.
        $db = \Illuminate\Support\Facades\DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', $db->getDriverName());
        $this->assertSame(':memory:', $db->getDatabaseName());
        $this->assertSame('', $db->select('PRAGMA database_list')[0]->file);
        foreach (['skp_records', 'objection_decisions', 'appeal_decisions', 'supreme_court_decisions', 'refund_processes'] as $table) {
            \Illuminate\Support\Facades\Schema::table($table, fn ($blueprint) => $blueprint->string('refund_amount', 30)->nullable()->change());
        }
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        $max = '999999999999999999.99';
        $payload = array_replace($this->payload($stage), ['refund_amount' => $max]);
        $this->postJson($this->endpoint($stage, $dedicated), $payload)->assertSuccessful();
        $this->assertSame($max, $this->case->fresh()->{$this->relation($stage)}->refund_amount);
        $this->assertSame($max, RefundProcess::sole()->refund_amount);
    }

    #[DataProvider('endpoints')]
    public function test_read_only_actor_cannot_finalize_manual_refund(int $stage, bool $dedicated): void
    {
        $this->prepareStage($stage);
        $this->case->update(['current_stage' => $stage]);
        $this->user->role->update(['permissions' => []]);
        $this->actingAs($this->user->fresh());
        $before = $this->snapshot();
        $this->postJson($this->endpoint($stage, $dedicated), $this->payload($stage))->assertForbidden();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stale_decision_and_deleted_refund_cannot_replace_financial_authority(): void
    {
        $this->prepareStage(7);
        $this->postJson($this->url(7), $this->payload(7))->assertOk();
        $decision = $this->case->fresh()->objectionDecision;
        $service = app(\App\Services\DecisionPointRefundService::class);
        $before = $this->snapshot();
        $decision->refund_amount = '1.00';
        try {
            $service->createIfRequested($decision, 7, $this->user->id);
            $this->fail('Stale amount must fail');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('refund_amount', $e->errors());
        }
        $this->assertSame($before, $this->snapshot());
        $decision->refresh();
        RefundProcess::sole()->delete();
        $before = $this->snapshot();
        try {
            $service->createIfRequested($decision, 7, $this->user->id);
            $this->fail('Deleted Refund must not be replaced');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('refund_amount', $e->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }


    #[DataProvider('origins')]
    public function test_lossy_sqlite_draft_storage_is_rejected_without_rounding(int $stage): void
    {
        $this->prepareStage($stage);
        $before = $this->snapshot();
        $this->postJson($this->url($stage), array_replace($this->payload($stage), ['action' => 'draft', 'refund_amount' => '9007199254740993.12']))->assertUnprocessable()->assertJsonValidationErrors('refund_amount');
        $this->assertSame($before, $this->snapshot());
    }


    public function test_duplicate_process_evidence_is_not_resolved_by_a_third_process(): void
    {
        $this->prepareStage(7);
        $this->postJson($this->url(7), $this->payload(7))->assertOk();
        $refund = RefundProcess::sole();
        $duplicate = $refund->getAttributes();
        unset($duplicate['id']);
        $duplicate['refund_number'] = 'DUPLICATE-SYNTHETIC';
        \Illuminate\Support\Facades\DB::table('refund_processes')->insert($duplicate);
        $before = $this->snapshot();
        try {
            app(\App\Services\DecisionPointRefundService::class)->createIfRequested($this->case->fresh()->objectionDecision, 7, $this->user->id);
            $this->fail('Duplicate evidence must block');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('refund_amount', $e->errors());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('refund_processes', 2);
    }

    public function test_selected_legacy_zero_stays_blocked_without_repair(): void
    {
        $this->prepareStage(7);
        $this->postJson($this->url(7), $this->payload(7))->assertOk();
        $refund = RefundProcess::sole();
        $refund->update(['refund_amount' => '0']);
        $this->case->fresh()->objectionDecision->update(['refund_amount' => '0']);
        $before = $this->snapshot();
        $base = '/api/tax-cases/'.$this->case->id.'/refunds/'.$refund->id.'/refund-stages/';
        $this->getJson($base.'1')->assertOk()->assertJsonPath('data.id', $refund->id)->assertJsonPath('data.refund_amount', '0.00');
        $this->getJson($base.'2')->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson($base.'2', ['request_number' => 'BLOCKED', 'request_date' => '2026-11-13', 'transfer_date' => '2026-11-13'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_terminal_dedicated_stage_twelve_rejects_continue(): void
    {
        $this->prepareStage(12);
        $this->case->update(['current_stage' => 12]);
        $before = $this->snapshot();
        $this->postJson($this->endpoint(12, true), $this->payload(12) + ['continue_to_next_stage' => true])->assertUnprocessable()->assertJsonValidationErrors('continue_to_next_stage');
        $this->assertSame($before, $this->snapshot());
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
