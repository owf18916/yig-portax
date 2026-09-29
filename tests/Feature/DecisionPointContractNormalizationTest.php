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
        $role = Role::create(['code' => 'STAFF', 'name' => 'Staff']);
        $this->user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        $open = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        CaseStatus::create(['code' => 'SUBMITTED', 'name' => 'Submitted']);

        $this->case = TaxCase::create([
            'user_id' => $this->user->id,
            'entity_id' => $entity->id,
            'fiscal_year_id' => $year->id,
            'currency_id' => $currency->id,
            'case_status_id' => $open->id,
            'case_number' => 'P1C-2026-01',
            'case_type' => 'CIT',
            'reported_amount' => 1000,
            'disputed_amount' => 1000,
            'current_stage' => 1,
        ]);

        $this->actingAs($this->user);
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
        $this->assertDatabaseHas('notification_logs', [
            'tax_case_id' => $this->case->id,
            'stage_id' => 12,
            'current_status' => 'QUEUED',
        ]);
        $this->assertDatabaseCount('notification_attempts', 1);
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

    private function prepareStage(int $stage): void
    {
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
