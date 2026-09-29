<?php

namespace Tests\Feature;

use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\RefundProcess;
use App\Models\Role;
use App\Models\SkpRecord;
use App\Models\TaxCase;
use App\Models\User;
use App\Models\WorkflowHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MainWorkflowTransitionGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TaxCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $entity = Entity::create([
            'code' => 'GUARD', 'name' => 'Guard Entity', 'entity_type' => 'AFFILIATE', 'tax_id' => 'GUARD-TAX',
        ]);
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
            'case_number' => 'GUARD-2026-01',
            'case_type' => 'CIT',
            'reported_amount' => 100,
            'disputed_amount' => 100,
            'current_stage' => 1,
        ]);
        $this->actingAs($this->user);
    }

    public function test_illegal_sequential_and_decision_gated_skips_are_rejected_before_writes(): void
    {
        $this->postJson($this->url(2), ['action' => 'draft'])->assertConflict();

        $this->submitHistory(3);
        $this->postJson($this->url(5), ['action' => 'draft'])->assertConflict();
        $this->postJson($this->url(9), ['action' => 'draft'])->assertConflict();

        $this->assertDatabaseCount('sp2_records', 0);
        $this->assertDatabaseCount('objection_submissions', 0);
        $this->assertDatabaseCount('appeal_explanation_requests', 0);
    }

    public static function stageFourChoices(): array
    {
        return [
            'continue without refund' => [false, true, true],
            'continue with refund' => [true, true, true],
            'refund without continue' => [true, false, false],
            'neither action' => [false, false, false],
        ];
    }

    #[DataProvider('stageFourChoices')]
    public function test_stage_five_depends_only_on_submitted_stage_four_continue_choice(
        bool $createRefund,
        bool $continue,
        bool $allowed
    ): void {
        foreach ([1, 2, 3, 4] as $stage) {
            $this->submitHistory($stage);
        }
        SkpRecord::create([
            'tax_case_id' => $this->case->id,
            'create_refund' => $createRefund,
            'continue_to_next_stage' => $continue,
        ]);

        if ($createRefund) {
            RefundProcess::create([
                'tax_case_id' => $this->case->id,
                'stage_id' => 4,
                'refund_number' => 'REF-GUARD-4',
                'refund_amount' => 25,
                'refund_method' => 'bank_transfer',
                'refund_status' => 'pending',
                'status' => 'draft',
                'sequence_number' => 1,
            ]);
        }

        $this->supportingDocument(5);
        $payload = [
            'action' => 'submit',
            'objection_number' => 'OBJ-01',
            'submission_date' => '2026-09-01',
            'objection_amount' => 75,
        ];
        $response = $this->postJson($this->url(5), $payload);

        if ($allowed) {
            $response->assertOk();
            $this->assertDatabaseHas('workflow_histories', [
                'tax_case_id' => $this->case->id, 'stage_id' => 5, 'status' => 'submitted',
            ]);
        } else {
            $response->assertConflict();
            $this->assertDatabaseMissing('objection_submissions', ['tax_case_id' => $this->case->id]);
        }

        $this->assertSame($createRefund ? 1 : 0, RefundProcess::count());
    }

    public function test_submitted_stage_rewrite_is_rejected_without_data_or_history_changes(): void
    {
        $this->supportingDocument(1);
        $this->postJson($this->url(1), [
            'action' => 'submit', 'disputed_amount' => 111,
        ])->assertOk();

        $this->postJson($this->url(1), [
            'action' => 'submit', 'disputed_amount' => 999,
        ])->assertConflict();

        $this->assertSame('111.00', $this->case->fresh()->disputed_amount);
        $this->assertSame(1, WorkflowHistory::where('stage_id', 1)->count());
    }

    public function test_completed_and_closed_cases_reject_new_workflow_writes(): void
    {
        $this->case->update(['is_completed' => true]);
        $this->postJson($this->url(1), ['action' => 'draft'])->assertConflict();

        $closed = CaseStatus::create(['code' => 'CLOSED', 'name' => 'Closed']);
        $this->case->update(['is_completed' => false, 'case_status_id' => $closed->id]);
        $this->postJson($this->url(1), ['action' => 'draft'])->assertConflict();

        $this->assertDatabaseCount('workflow_histories', 0);
    }

    public function test_noncanonical_stage_values_are_rejected(): void
    {
        foreach ([0, 13, 15, 99] as $stage) {
            $this->postJson($this->url($stage), ['action' => 'draft'])->assertUnprocessable();
        }

        $this->assertDatabaseCount('workflow_histories', 0);
    }

    public function test_normal_one_through_five_progression_remains_available(): void
    {
        foreach ([1, 2, 3] as $stage) {
            $this->supportingDocument($stage);
            $this->postJson($this->url($stage), ['action' => 'submit'])->assertOk();
        }

        $this->supportingDocument(4);
        $this->postJson($this->url(4), [
            'action' => 'submit',
            'create_refund' => true,
            'continue_to_next_stage' => true,
        ])->assertOk();

        $this->getJson("/api/tax-cases/{$this->case->id}")
            ->assertOk()
            ->assertJsonPath('data.accessible_stages.4', 5);

        $this->supportingDocument(5);
        $this->postJson($this->url(5), [
            'action' => 'submit',
            'objection_number' => 'OBJ-NORMAL',
            'submission_date' => '2026-09-01',
            'objection_amount' => 75,
        ])->assertOk();

        $this->assertSame([1, 2, 3, 4, 5], WorkflowHistory::query()
            ->where('status', 'submitted')->orderBy('stage_id')->pluck('stage_id')->all());
    }

    private function url(int $stage): string
    {
        return "/api/tax-cases/{$this->case->id}/workflow/{$stage}";
    }

    private function submitHistory(int $stage): void
    {
        WorkflowHistory::create([
            'tax_case_id' => $this->case->id,
            'stage_id' => $stage,
            'action' => 'submitted',
            'status' => 'submitted',
            'user_id' => $this->user->id,
        ]);
    }

    private function supportingDocument(int $stage): void
    {
        Document::create([
            'documentable_type' => TaxCase::class,
            'documentable_id' => $this->case->id,
            'tax_case_id' => $this->case->id,
            'document_type' => 'supporting_document',
            'stage_code' => (string) $stage,
            'original_filename' => "stage-{$stage}.pdf",
            'file_path' => "tests/stage-{$stage}.pdf",
            'file_mime_type' => 'application/pdf',
            'file_size' => 1,
            'uploaded_by' => $this->user->id,
            'uploaded_at' => now(),
            'status' => 'ACTIVE',
        ]);
    }
}
