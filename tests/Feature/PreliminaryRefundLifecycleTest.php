<?php

namespace Tests\Feature;

use App\Models\BankTransferRequest;
use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\PreliminaryRefundRequest;
use App\Models\RefundProcess;
use App\Models\Role;
use App\Models\StatusHistory;
use App\Models\TaxCase;
use App\Models\User;
use App\Models\WorkflowHistory;
use App\Services\MainWorkflowStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreliminaryRefundLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private TaxCase $case;

    private User $staff;

    private User $holding;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $entity = Entity::create(['code' => 'PRA', 'name' => 'Affiliate', 'entity_type' => 'AFFILIATE', 'tax_id' => 'PRA']);
        $holding = Entity::create(['code' => 'PRH', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'PRH']);
        $role = Role::create(['code' => 'STAFF', 'name' => 'Staff', 'permissions' => ['submit_cases'], 'is_active' => true]);
        $this->staff = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
        $this->holding = User::factory()->create(['entity_id' => $holding->id, 'role_id' => $role->id, 'is_active' => true]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        $status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        CaseStatus::create(['code' => 'CLOSED', 'name' => 'Closed']);
        $this->case = TaxCase::create(['entity_id' => $entity->id, 'user_id' => $this->staff->id, 'fiscal_year_id' => $year->id, 'case_number' => 'PRELIMINARY', 'case_type' => 'VAT', 'case_status_id' => $status->id, 'currency_id' => $currency->id, 'spt_position_type' => 'LB', 'spt_type' => 'Pengembalian Pendahuluan', 'reported_amount' => '1000.00', 'disputed_amount' => '1000.00', 'current_stage' => 1]);
        WorkflowHistory::create(['tax_case_id' => $this->case->id, 'stage_id' => 1, 'action' => 'submitted', 'status' => 'submitted', 'user_id' => $this->staff->id]);
        $this->base = '/api/tax-cases/'.$this->case->id;
        $this->actingAs($this->staff);
    }

    private function pdf(string $content = 'evidence'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\n".$content."\n%%EOF");
    }

    // Existing financial/authorization regressions now use the required evidence contract.
    private function commandPost(string $url, array $data = [])
    {
        if (str_contains($url, '/preliminary-refund-request')) {
            $data += ['document' => $this->pdf(), 'decision_date' => '2026-10-29'];
        }

        return $this->postJson($url, $data);
    }

    public function test_submission_persists_owned_private_evidence_and_identical_retry_is_noop(): void
    {
        $request = $this->request();
        $this->assertSame('SUBMITTED', $request->approval_status);
        $document = $request->submissionDocument;
        $this->assertSame($request->id, (int) $document->documentable_id);
        $this->assertSame($this->case->id, (int) $document->tax_case_id);
        $this->assertSame('preliminary_submission', $document->document_type);
        $this->assertSame('ACTIVE', $document->status);
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertSame(hash('sha256', "%PDF-1.4\nevidence\n%%EOF"), $document->hash);
        $this->request();
        $this->assertDatabaseCount('documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('refund_processes', 0);
        $this->getJson($this->base.'/preliminary-refund-request')->assertOk()->assertJsonMissingPath('data.submission_document.file_path');
        $this->commandPost($this->base.'/preliminary-refund-request', ['submission_date' => '2026-10-30', 'requested_amount' => '900.13'])->assertUnprocessable();
        $this->assertDatabaseCount('documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_missing_invalid_submission_and_spoofed_references_are_atomic(): void
    {
        $url = $this->base.'/preliminary-refund-request';
        $data = ['submission_date' => '2026-10-30', 'requested_amount' => '900.12'];
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->postJson($url, $data + ['document' => UploadedFile::fake()->createWithContent('bad.pdf', 'not a PDF')])->assertUnprocessable();
        $this->postJson($url, $data + ['document' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')])->assertUnprocessable();
        $this->postJson($url, ['requested_amount' => '900.12', 'document' => $this->pdf()])->assertUnprocessable();
        foreach (['PENDING', 'POSTPONED', 'SUBMITTED', 'APPROVED'] as $status) {
            $this->commandPost($url, $data + ['approval_status' => $status])->assertUnprocessable();
        }
        $this->commandPost($url, $data + ['submission_document_id' => 999])->assertUnprocessable();
        $this->assertDatabaseCount('preliminary_refund_requests', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_decision_requires_actual_date_and_document_without_state_change(): void
    {
        $request = $this->request();
        foreach (['approve', 'reject'] as $outcome) {
            $url = $this->base.'/preliminary-refund-request/'.$request->id.'/'.$outcome;
            $this->postJson($url, ['approved_amount' => '800.12', 'reason' => 'External rejection', 'decision_date' => '2026-10-29'])->assertUnprocessable();
            $this->postJson($url, ['approved_amount' => '800.12', 'reason' => 'External rejection', 'document' => $this->pdf()])->assertUnprocessable();
        }
        $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
        $this->assertNull($request->fresh()->decision_document_id);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_rejection_evidence_retry_and_conflicting_replacement(): void
    {
        $request = $this->request();
        $url = $this->base.'/preliminary-refund-request/'.$request->id.'/reject';
        $data = ['reason' => 'External rejection', 'decision_date' => '2026-10-27', 'document' => $this->pdf('decision')];
        $this->commandPost($url, $data)->assertOk();
        $attributes = $request->fresh()->getAttributes();
        $this->commandPost($url, $data)->assertOk();
        $this->commandPost($url, array_replace($data, ['document' => $this->pdf('changed')]))->assertUnprocessable();
        $this->commandPost($url, array_replace($data, ['decision_date' => '2026-10-28']))->assertUnprocessable();
        $this->assertSame($attributes, $request->fresh()->getAttributes());
        $this->assertSame('2026-10-27', $request->fresh()->decision_date->toDateString());
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('documents', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_distinct_documents_survive_closed_and_entity_and_ownership_checks(): void
    {
        $refund = $this->paymentReady();
        $request = PreliminaryRefundRequest::sole();
        $this->assertNotSame($request->submission_document_id, $request->decision_document_id);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertOk();
        foreach ([$request->submissionDocument, $request->decisionDocument] as $document) {
            $this->get('/api/documents/'.$document->id.'/download')->assertOk();
            $this->get('/api/documents/'.$document->id.'/view')->assertOk();
            $this->deleteJson('/api/documents/'.$document->id)->assertUnprocessable();
            $this->assertNull($document->fresh()->deleted_at);
            Storage::disk('local')->assertExists($document->file_path);
        }
        $this->getJson($this->base)->assertOk()->assertJsonMissingPath('data.documents.0.file_path');
        $this->actingAs(User::factory()->create(['entity_id' => null]));
        $this->get('/api/documents/'.$request->submission_document_id.'/download')->assertForbidden();
        $this->getJson($this->base.'/preliminary-refund-request')->assertForbidden();
    }

    public function test_generic_upload_cannot_forge_preliminary_ownership(): void
    {
        $request = $this->request();
        $this->postJson('/api/documents', ['tax_case_id' => $this->case->id, 'documentable_type' => PreliminaryRefundRequest::class,
            'documentable_id' => $request->id, 'stage_code' => '0', 'document_type' => 'preliminary_decision', 'file' => $this->pdf()])->assertUnprocessable();
        $this->assertDatabaseCount('documents', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_historical_pending_postponed_and_missing_evidence_stay_readonly(): void
    {
        $request = PreliminaryRefundRequest::create(['tax_case_id' => $this->case->id, 'approval_status' => 'PENDING', 'requested_amount' => '900.12']);
        foreach (['PENDING', 'POSTPONED', 'APPROVED', 'REJECTED', 'SUBMITTED'] as $status) {
            $request->update(['approval_status' => $status]);
            $before = $request->fresh()->getAttributes();
            $url = $this->base.'/preliminary-refund-request/'.$request->id;
            $this->getJson($this->base.'/preliminary-refund-request')->assertOk()->assertJsonPath('data.approval_status', $status);
            $this->commandPost($url.'/approve', ['approved_amount' => '800.12'])->assertUnprocessable();
            $this->commandPost($url.'/reject', ['reason' => 'External rejection'])->assertUnprocessable();
            $this->putJson($url, ['notes' => 'Rewrite'])->assertUnprocessable();
            $this->deleteJson($url)->assertUnprocessable();
            $this->assertSame($before, $request->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_submission_document_failure_rolls_back_request_and_new_file(): void
    {
        \App\Models\Document::creating(function () {
            throw new \RuntimeException('injected metadata failure');
        });
        try {
            $this->commandPost($this->base.'/preliminary-refund-request', ['submission_date' => '2026-10-30', 'requested_amount' => '900.12'])->assertStatus(500);
            $this->assertDatabaseCount('preliminary_refund_requests', 0);
            $this->assertDatabaseCount('documents', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            \App\Models\Document::clearBootedModels();
            \App\Models\Document::flushEventListeners();
        }
    }

    public function test_storage_failure_cannot_persist_submission_or_decision(): void
    {
        $request = $this->request();
        $original = Storage::disk('local');
        $broken = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class);
        $broken->shouldReceive('putFileAs')->twice()->andReturn(false);
        $broken->shouldReceive('delete')->twice()->andReturn(true);
        Storage::set('local', $broken);
        try {
            $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertStatus(500);
            $this->commandPost($this->base.'/preliminary-refund-request', ['submission_date' => '2026-10-30', 'requested_amount' => '900.12'])->assertStatus(500);
            $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
            $this->assertDatabaseCount('preliminary_refund_requests', 1);
            $this->assertDatabaseCount('documents', 1);
            $this->assertDatabaseCount('refund_processes', 0);
        } finally {
            Storage::set('local', $original);
        }
    }

    public function test_forward_migration_preserves_historical_rows_and_retry_is_noop(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite upgrade fixture; PostgreSQL needs its own isolated preflight.');
        }
        $request = PreliminaryRefundRequest::create(['tax_case_id' => $this->case->id, 'approval_status' => 'POSTPONED', 'requested_amount' => '900.12', 'status_comment' => 'Historical outcome']);
        $before = $request->getAttributes();
        \Illuminate\Support\Facades\Schema::table('preliminary_refund_requests', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropForeign(['submission_document_id']);
            $table->dropForeign(['decision_document_id']);
            $table->dropColumn(['submission_document_id', 'decision_document_id', 'decision_date']);
            $table->enum('approval_status', ['PENDING', 'APPROVED', 'REJECTED', 'POSTPONED'])->default('PENDING')->change();
        });
        $migration = require database_path('migrations/2026_10_30_000002_add_preliminary_submission_evidence.php');
        $migration->up();
        foreach ($before as $key => $value) {
            $this->assertEquals($value, $request->fresh()->getRawOriginal($key));
        }
        $this->assertNull($request->fresh()->submission_document_id);
        $this->assertNull($request->fresh()->decision_date);
        $columns = collect(\Illuminate\Support\Facades\Schema::getColumns('preliminary_refund_requests'))->keyBy('name');
        $this->assertStringContainsString('SUBMITTED', $columns['approval_status']['default']);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]));
        $this->assertSame('POSTPONED', $request->fresh()->approval_status);
        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::transaction(fn () => $request->update(['approval_status' => 'DRAFT']));
    }

    public function test_approval_failure_removes_only_new_decision_file_and_metadata(): void
    {
        $request = $this->request();
        $path = $request->submissionDocument->file_path;
        RefundProcess::creating(function () {
            throw new \RuntimeException('injected refund failure');
        });
        try {
            $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertStatus(500);
            $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
            $this->assertNull($request->fresh()->decision_document_id);
            $this->assertDatabaseCount('documents', 1);
            $this->assertDatabaseCount('refund_processes', 0);
            $this->assertSame([$path], Storage::disk('local')->allFiles());
        } finally {
            RefundProcess::flushEventListeners();
        }
    }

    public function test_cross_case_document_substitution_fails_closed(): void
    {
        $request = $this->request();
        $foreignCase = $this->case->replicate();
        $foreignCase->case_number = 'FOREIGN-EVIDENCE';
        $foreignCase->save();
        $foreign = $request->submissionDocument->replicate();
        $foreign->tax_case_id = $foreignCase->id;
        $foreign->save();
        $request->update(['submission_document_id' => $foreign->id]);
        $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertUnprocessable();
        $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->get('/api/documents/'.$foreign->id.'/download')->assertNotFound();
    }

    public function test_approval_retry_compares_decision_date_document_and_reason(): void
    {
        $refund = $this->approved();
        $request = PreliminaryRefundRequest::sole();
        $before = $request->getAttributes();
        $url = $this->base.'/preliminary-refund-request/'.$request->id.'/approve';
        foreach ([['document' => $this->pdf('replacement')], ['decision_date' => '2026-10-28'], ['reason' => 'Replacement reason']] as $change) {
            $this->commandPost($url, $change + ['approved_amount' => '800.12'])->assertUnprocessable();
        }
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame('2026-10-29', $request->fresh()->decision_date->toDateString());
        $this->assertSame('2026-10-29', $request->fresh()->approved_date->toDateString());
        $this->assertDatabaseCount('documents', 2);
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->assertDatabaseCount('refund_processes', 1);
        $this->assertSame($refund->id, RefundProcess::sole()->id);
        $this->deleteJson('/api/documents/'.$request->submission_document_id)->assertUnprocessable();
        $this->assertNull($request->submissionDocument->fresh()->deleted_at);
    }

    public function test_missing_or_deleted_submission_evidence_cannot_receive_decision(): void
    {
        $request = $this->request();
        $document = $request->submissionDocument;
        Storage::disk('local')->delete($document->file_path);
        $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertUnprocessable();
        \App\Models\Document::whereKey($document->id)->update(['deleted_at' => now()]);
        $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/reject', ['reason' => 'External rejection'])->assertUnprocessable();
        $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_compensation_retains_file_if_metadata_commit_was_acknowledged_as_failure(): void
    {
        $request = $this->request();
        $evidence = app(\App\Services\PreliminaryRefundEvidence::class);
        try {
            $evidence->record($this->pdf('decision'), $this->case->id, function ($metadata) use ($evidence, $request) {
                // Simulate SQL persisted, then the caller lost the commit response.
                $document = $evidence->attach($request, 'decision', $metadata);
                $request->update(['decision_document_id' => $document->id]);
                throw new \RuntimeException('ambiguous acknowledgement');
            });
            $this->fail('Injected acknowledgement failure was not raised.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('ambiguous acknowledgement', $failure->getMessage());
        }
        Storage::disk('local')->assertExists($request->fresh()->decisionDocument->file_path);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_submission_and_decision_queries_are_bounded(): void
    {
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            $request = $this->request();
            $submissionCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::flushQueryLog();
            $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertOk();
            $decisionCount = count(\Illuminate\Support\Facades\DB::getQueryLog());
            $this->assertLessThanOrEqual(40, $submissionCount);
            $this->assertLessThanOrEqual(40, $decisionCount);
            fwrite(STDOUT, 'Preliminary evidence HTTP SQL: submission='.$submissionCount.', decision='.$decisionCount.PHP_EOL);
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
            \Illuminate\Support\Facades\DB::flushQueryLog();
        }
    }

    private function request(): PreliminaryRefundRequest
    {
        $this->commandPost($this->base.'/preliminary-refund-request', ['submission_date' => '2026-10-30', 'requested_amount' => '900.12'])->assertCreated();

        return PreliminaryRefundRequest::sole();
    }

    private function approved(): RefundProcess
    {
        $request = $this->request();
        $this->actingAs($this->staff)->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertOk();
        $this->assertFalse($this->case->fresh()->is_completed);

        return RefundProcess::sole();
    }

    private function paymentReady(): RefundProcess
    {
        $refund = $this->approved();
        $this->commandPost($this->paymentUrl($refund, 2), ['request_number' => 'REQ-1', 'request_date' => '2026-10-30', 'transfer_date' => '2026-10-30'])->assertOk();
        $this->commandPost($this->paymentUrl($refund, 3), ['instruction_number' => 'INS-1', 'instruction_issue_date' => '2026-10-30', 'instruction_received_date' => '2026-10-30', 'transfer_amount' => null])->assertOk();

        return $refund;
    }

    private function paymentUrl(RefundProcess $refund, int $stage): string
    {
        return $this->base.'/refunds/'.$refund->id.'/refund-stages/'.$stage;
    }

    private function receipt(string $amount = '800.12'): array
    {
        return ['receipt_number' => 'RCPT-1', 'received_date' => '2026-10-30', 'received_amount' => $amount];
    }

    public function test_single_full_receipt_closes_atomically_and_retry_is_noop(): void
    {
        $refund = $this->paymentReady();
        $this->assertSame('800.12', $refund->refund_amount);
        $this->assertFalse($this->case->fresh()->is_completed);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertOk();
        $case = $this->case->fresh('status');
        $this->assertSame('CLOSED', $case->status->code);
        $this->assertTrue($case->is_completed);
        $this->assertSame('completed', $refund->fresh()->refund_status);
        $this->assertSame('800.12', BankTransferRequest::sole()->received_amount);
        $this->assertSame([1], app(MainWorkflowStateResolver::class)->resolve($case)['completed_stages']);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertOk();
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt('800.13'))->assertUnprocessable();
        $this->assertDatabaseCount('status_histories', 1);
        $this->assertDatabaseCount('refund_processes', 1);
        $this->assertDatabaseCount('bank_transfer_requests', 1);
        $this->getJson($this->base)->assertOk()->assertJsonPath('data.is_completed', true)->assertJsonPath('data.status.code', 'CLOSED');
        $this->getJson($this->paymentUrl($refund, 4))->assertOk()->assertJsonPath('data.received_amount', '800.12');
        $this->commandPost($this->paymentUrl($refund, 3), ['instruction_number' => 'NEW', 'instruction_issue_date' => '2026-10-30', 'instruction_received_date' => '2026-10-30'])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public static function badAmounts(): array
    {
        return [['0'], ['0.00'], ['400.06'], ['800.11'], ['800.13'], ['900.12'], ['800.120'], ['8e2']];
    }

    #[DataProvider('badAmounts')]
    public function test_inexact_zero_partial_overpayment_never_close(string $amount): void
    {
        $refund = $this->paymentReady();
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt($amount))->assertUnprocessable();
        $this->assertFalse($this->case->fresh()->is_completed);
        $this->assertNull(BankTransferRequest::sole()->received_amount);
        $this->assertDatabaseCount('status_histories', 0);
    }

    public function test_approval_identity_authority_and_idempotency(): void
    {
        $request = $this->request();
        $url = $this->base.'/preliminary-refund-request/'.$request->id.'/approve';
        $this->getJson($this->base)->assertOk()->assertJsonPath('data.preliminary_can_decide', true);
        $other = $this->case->replicate();
        $other->case_number = 'OTHER';
        $other->save();
        $foreign = PreliminaryRefundRequest::create(['tax_case_id' => $other->id, 'requested_amount' => '800.12']);
        $this->actingAs($this->staff)->commandPost($this->base.'/preliminary-refund-request/'.$foreign->id.'/approve', ['approved_amount' => '800.12'])->assertNotFound();
        $this->commandPost($url, ['approved_amount' => '800.12'])->assertOk();
        $this->commandPost($url, ['approved_amount' => '800.12'])->assertOk();
        $this->commandPost($url, ['approved_amount' => '800.13'])->assertUnprocessable();
        $this->assertDatabaseCount('refund_processes', 1);
        $this->commandPost($this->base.'/refund-processes', ['stage_id' => 0, 'refund_amount' => '800.12'])->assertUnprocessable();
        $this->commandPost($this->base.'/refund-stages/1', ['stage_id' => 0, 'refund_amount' => '800.12'])->assertUnprocessable();
    }

    public static function ineligible(): array
    {
        return [['CIT', 'LB'], ['VAT', 'KB'], ['VAT', 'NIHIL']];
    }

    #[DataProvider('ineligible')]
    public function test_ineligible_case_is_refused(string $type, string $position): void
    {
        $this->case->update(['case_type' => $type, 'spt_position_type' => $position]);
        $this->commandPost($this->base.'/preliminary-refund-request', ['submission_date' => '2026-10-30', 'requested_amount' => '800.12'])->assertUnprocessable();
        $this->assertDatabaseCount('preliminary_refund_requests', 0);
    }

    public function test_unfiled_rejected_deleted_and_cross_entity_requests_cannot_fund(): void
    {
        WorkflowHistory::query()->delete();
        $this->commandPost($this->base.'/preliminary-refund-request', ['submission_date' => '2026-10-30', 'requested_amount' => '800.12'])->assertUnprocessable();
        WorkflowHistory::create(['tax_case_id' => $this->case->id, 'stage_id' => 1, 'action' => 'submitted', 'status' => 'submitted', 'user_id' => $this->staff->id]);
        $request = $this->request();
        $outsider = User::factory()->create(['entity_id' => null]);
        $this->actingAs($outsider)->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertForbidden();
        $this->actingAs($this->staff)->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/reject', ['reason' => 'Administrative rejection'])->assertOk();
        $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertUnprocessable();
        $request->delete();
        $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertNotFound();
        $this->commandPost($this->base.'/preliminary-refund-request', ['submission_date' => '2026-10-30', 'requested_amount' => '800.12'])->assertUnprocessable();
        $this->assertDatabaseCount('refund_processes', 0);
    }

    public function test_missing_instruction_receipt_and_split_evidence_never_close(): void
    {
        $refund = $this->approved();
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertUnprocessable();
        $this->commandPost($this->paymentUrl($refund, 2), ['request_number' => 'REQ', 'request_date' => '2026-10-30', 'transfer_date' => '2026-10-30'])->assertOk();
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertUnprocessable();
        $transfer = BankTransferRequest::sole();
        $split = $transfer->replicate();
        $split->transfer_number = 'SPLIT';
        $split->save();
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertUnprocessable();
        $this->assertFalse($this->case->fresh()->is_completed);
        $this->assertDatabaseCount('bank_transfer_requests', 2);
    }

    public function test_all_legacy_completion_and_close_adapters_refuse_preliminary(): void
    {
        $refund = $this->paymentReady();
        $transfer = BankTransferRequest::sole();
        foreach (['/workflow/15', '/refunds/'.$refund->id.'/workflow/15', '/refund-processes/'.$refund->id.'/bank-transfers/'.$transfer->id.'/process', '/complete', '/close'] as $path) {
            $this->commandPost($this->base.$path, $this->receipt())->assertUnprocessable();
        }
        $this->commandPost($this->base.'/refund-stages/4', $this->receipt())->assertUnprocessable();
        $this->commandPost($this->paymentUrl($refund, 4), ['received_amount' => '800.12'])->assertUnprocessable();
        $this->assertFalse($this->case->fresh()->is_completed);
    }

    public function test_ambiguous_historical_refunds_are_preserved_and_refused(): void
    {
        $refund = $this->paymentReady();
        $other = $refund->replicate();
        $other->refund_number = 'LEGACY-OTHER';
        $other->save();
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertUnprocessable();
        $this->assertDatabaseCount('refund_processes', 2);
        $this->assertFalse($this->case->fresh()->is_completed);
    }

    public function test_completion_rolls_back_all_state_on_history_failure(): void
    {
        $refund = $this->paymentReady();
        StatusHistory::creating(function () {
            throw new \RuntimeException('injected history failure');
        });
        try {
            $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertStatus(500);
            $this->assertFalse($this->case->fresh()->is_completed);
            $this->assertSame('processed', $refund->fresh()->refund_status);
            $this->assertNull(BankTransferRequest::sole()->received_amount);
            $this->assertDatabaseCount('status_histories', 0);
        } finally {
            StatusHistory::flushEventListeners();
        }
    }

    public function test_approval_rolls_back_on_refund_creation_failure(): void
    {
        $request = $this->request();
        RefundProcess::creating(function () {
            throw new \RuntimeException('injected Refund failure');
        });
        try {
            $this->actingAs($this->staff)->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => '800.12'])->assertStatus(500);
            $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
            $this->assertDatabaseCount('refund_processes', 0);
        } finally {
            RefundProcess::flushEventListeners();
        }
    }

    public function test_closed_documents_are_readable_and_cannot_be_deleted(): void
    {
        $refund = $this->paymentReady();
        $document = \App\Models\Document::create(['tax_case_id' => $this->case->id, 'documentable_type' => TaxCase::class, 'documentable_id' => $this->case->id, 'stage_code' => '0', 'document_type' => 'supporting', 'original_filename' => 'receipt.pdf', 'file_path' => 'testing/receipt.pdf', 'file_size' => 1, 'file_mime_type' => 'application/pdf', 'uploaded_at' => now(), 'uploaded_by' => $this->staff->id, 'status' => 'ACTIVE']);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertOk();
        $this->getJson('/api/documents?tax_case_id='.$this->case->id)->assertOk();
        $this->deleteJson('/api/documents/'.$document->id)->assertUnprocessable();
        $this->assertFalse($document->fresh()->trashed());
        $this->getJson($this->base.'/workflow-history')->assertOk();
    }

    public function test_refund_transfer_mismatch_and_existing_partial_receipt_are_not_repaired(): void
    {
        $refund = $this->paymentReady();
        $refund->update(['refund_amount' => '800.11']);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertUnprocessable();
        $this->assertSame('800.11', $refund->fresh()->refund_amount);
        $refund->update(['refund_amount' => '800.12']);
        $transfer = BankTransferRequest::sole();
        $transfer->update(['transfer_amount' => '400.06']);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertUnprocessable();
        $transfer->update(['transfer_amount' => '800.12', 'received_amount' => '400.06']);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertUnprocessable();
        $this->assertSame('400.06', $transfer->fresh()->received_amount);
        $this->assertFalse($this->case->fresh()->is_completed);
    }

    public function test_missing_receipt_number_or_date_cannot_close(): void
    {
        $refund = $this->paymentReady();
        foreach (['receipt_number', 'received_date', 'received_amount'] as $field) {
            $data = $this->receipt();
            unset($data[$field]);
            $this->commandPost($this->paymentUrl($refund, 4), $data)->assertUnprocessable();
        }
        $this->assertFalse($this->case->fresh()->is_completed);
    }

    public function test_crafted_refund_ids_and_main_audit_routes_cannot_bypass(): void
    {
        $refund = $this->paymentReady();
        $other = $this->case->replicate();
        $other->case_number = 'OTHER';
        $other->save();
        $this->commandPost('/api/tax-cases/'.$other->id.'/refunds/'.$refund->id.'/refund-stages/4', $this->receipt())->assertNotFound();
        foreach (['/workflow/2', '/workflow/12', '/supreme-court-decisions'] as $path) {
            $this->commandPost($this->base.$path, ['action' => 'submit'])->assertUnprocessable();
        }
        $state = app(MainWorkflowStateResolver::class)->resolve($this->case->fresh());
        $this->assertSame([], $state['available_stages']);
        $this->assertSame([1], $state['completed_stages']);
        $this->assertFalse($this->case->fresh()->is_completed);
    }

    public function test_ordinary_refund_receipt_keeps_existing_partial_semantics(): void
    {
        $this->case->update(['spt_type' => 'Restitusi']);
        $refund = RefundProcess::create(['tax_case_id' => $this->case->id, 'stage_id' => 4, 'stage_source' => 'SKP', 'refund_number' => 'NORMAL', 'refund_amount' => '800.12', 'refund_status' => 'processed', 'refund_method' => 'bank_transfer']);
        BankTransferRequest::create(['refund_process_id' => $refund->id, 'request_number' => 'NORMAL', 'transfer_amount' => '800.12', 'transfer_status' => 'processing', 'created_by' => $this->staff->id]);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt('400.06'))->assertOk();
        $this->assertSame('completed', $refund->fresh()->refund_status);
        $this->assertFalse($this->case->fresh()->is_completed);
    }

    public function test_ordinary_methods_cannot_enter_preliminary_administration(): void
    {
        foreach (['Restitusi', 'Kompensasi', null] as $method) {
            $this->case->update(['spt_type' => $method]);
            $this->commandPost($this->base.'/preliminary-refund-request', ['submission_date' => '2026-10-30', 'requested_amount' => '800.12'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('preliminary_refund_requests', 0);
    }

    public function test_legacy_source_and_uppercase_tokens_are_read_without_rewriting(): void
    {
        $refund = new RefundProcess(['stage_source' => 'PRELIMINARY', 'refund_status' => 'PROCESSED']);
        $transfer = new BankTransferRequest(['transfer_status' => 'PROCESSING', 'instruction_received_date' => '2026-10-30']);
        $refund->setRelation('bankTransferRequests', new \Illuminate\Database\Eloquent\Collection([$transfer]));
        $this->assertTrue($refund->isPreliminary());
        $this->assertSame(3, $refund->getCurrentRefundStage());
        $this->assertSame('PROCESSING', $transfer->transfer_status);
        $this->assertNull($refund->stage_id);
        $refund = $this->paymentReady();
        $refund->update(['stage_id' => 4]);
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertUnprocessable();
        $this->assertSame(4, $refund->fresh()->stage_id);
        $this->assertFalse($this->case->fresh()->is_completed);
    }

    public function test_fractional_json_float_approval_is_refused_without_coercion(): void
    {
        $request = $this->request();
        $this->actingAs($this->staff)->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/approve', ['approved_amount' => 800.12])->assertUnprocessable();
        $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
        $this->assertSame('0.00', $request->fresh()->approved_amount);
        $this->assertDatabaseCount('refund_processes', 0);
    }

    public function test_completion_has_bounded_queries_and_one_transition_write(): void
    {
        $refund = $this->paymentReady();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        try {
            $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertOk();
            $queries = \Illuminate\Support\Facades\DB::getQueryLog();
            $this->assertLessThanOrEqual(40, count($queries));
            fwrite(STDOUT, 'Preliminary full receipt HTTP SQL statements: '.count($queries).PHP_EOL);
            $this->assertCount(1, array_filter($queries, fn ($query) => str_starts_with($query['query'], 'insert into "status_histories"')));
        } finally {
            \Illuminate\Support\Facades\DB::disableQueryLog();
        }
    }

    public static function waitingOutcomes(): array
    {
        return [['reject', 'REJECTED'], ['postpone', 'POSTPONED']];
    }

    #[DataProvider('waitingOutcomes')]
    public function test_external_nonapproval_preserves_evidence_and_blocks_followup(string $action, string $status): void
    {
        $request = $this->request();
        $url = $this->base.'/preliminary-refund-request/'.$request->id;
        $this->assertSame('SUBMITTED', $request->approval_status);
        $this->assertDatabaseCount('refund_processes', 0);
        if ($action === 'postpone') {
            $this->commandPost($url.'/'.$action, ['reason' => 'External Tax Office outcome'])->assertUnprocessable();
            $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
            $this->assertDatabaseCount('refund_processes', 0);

            return;
        }
        $this->commandPost($url.'/'.$action, ['reason' => 'External Tax Office outcome'])->assertOk()->assertJsonPath('data.approval_status', $status);
        $evidence = $request->fresh()->getAttributes();
        $this->assertSame($this->staff->id, $request->fresh()->approved_by);
        $this->assertNotNull($request->fresh()->approved_at);
        foreach (['approve', 'reject', 'postpone'] as $next) {
            $this->commandPost($url.'/'.$next, ['approved_amount' => '800.12', 'reason' => 'Attempt to replace outcome'])->assertUnprocessable();
        }
        $this->putJson($url, ['notes' => 'Replace evidence'])->assertUnprocessable();
        $this->deleteJson($url)->assertUnprocessable();
        $this->assertSame($evidence, $request->fresh()->getAttributes());
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('status_histories', 0);
        $this->assertFalse($this->case->fresh()->is_completed);
        Queue::assertNothingPushed();
    }

    public static function unauthorizedRecorders(): array
    {
        return [['viewer'], ['holding_viewer'], ['cross_entity'], ['unassigned'], ['inactive_user'], ['inactive_role']];
    }

    #[DataProvider('unauthorizedRecorders')]
    public function test_external_outcomes_require_recording_permission_and_case_boundary(string $kind): void
    {
        $request = $this->request();
        $entityId = $kind === 'holding_viewer' || $kind === 'cross_entity' ? $this->holding->entity_id : $this->staff->entity_id;
        if ($kind === 'cross_entity') {
            Entity::findOrFail($entityId)->update(['entity_type' => 'AFFILIATE']);
        }
        $role = Role::create(['code' => 'LIMITED', 'name' => 'Limited', 'permissions' => in_array($kind, ['viewer', 'holding_viewer'], true) ? ['view_cases'] : ['submit_cases'], 'is_active' => $kind !== 'inactive_role']);
        $user = User::factory()->create(['entity_id' => $kind === 'unassigned' ? null : $entityId, 'role_id' => $role->id, 'is_active' => $kind !== 'inactive_user']);
        $this->actingAs($user);
        foreach (['approve', 'reject', 'postpone'] as $action) {
            $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/'.$action, ['approved_amount' => '800.12', 'reason' => 'External outcome recorded'])->assertForbidden();
        }
        $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
        $this->assertDatabaseCount('refund_processes', 0);
        if ($kind === 'viewer' || $kind === 'holding_viewer') {
            $this->getJson($this->base)->assertOk()->assertJsonPath('data.preliminary_can_decide', false);
            $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
            app(\App\Services\PreliminaryRefundLifecycle::class)->decide($this->case->id, $request->id, 'approve', '800.12');
        }
    }

    public function test_approved_external_evidence_cannot_be_replaced_before_or_after_payment(): void
    {
        $refund = $this->paymentReady();
        $request = PreliminaryRefundRequest::sole();
        $evidence = $request->getAttributes();
        $url = $this->base.'/preliminary-refund-request/'.$request->id;
        foreach (['reject', 'postpone'] as $action) {
            $this->commandPost($url.'/'.$action, ['reason' => 'Attempt to replace approval'])->assertUnprocessable();
        }
        $this->actingAs($this->holding)->commandPost($url.'/approve', ['approved_amount' => '800.12'])->assertOk();
        $this->assertSame($evidence, $request->fresh()->getAttributes());
        $this->commandPost($this->paymentUrl($refund, 4), $this->receipt())->assertOk();
        $this->commandPost($url.'/approve', ['approved_amount' => '800.13'])->assertUnprocessable();
        $this->assertSame($evidence, $request->fresh()->getAttributes());
        $this->assertDatabaseCount('refund_processes', 1);
        Queue::assertNothingPushed();
    }

    public function test_postponed_requires_reason_and_foreign_child_is_refused(): void
    {
        $request = $this->request();
        $this->commandPost($this->base.'/preliminary-refund-request/'.$request->id.'/postpone', [])->assertUnprocessable();
        $other = $this->case->replicate();
        $other->case_number = 'FOREIGN';
        $other->save();
        $foreign = PreliminaryRefundRequest::create(['tax_case_id' => $other->id, 'requested_amount' => '800.12']);
        $this->commandPost($this->base.'/preliminary-refund-request/'.$foreign->id.'/postpone', ['reason' => 'External postponement'])->assertNotFound();
        $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
    }

    public function test_pending_external_request_cannot_be_funded_and_approval_money_is_bounded(): void
    {
        $request = $this->request();
        $url = $this->base.'/preliminary-refund-request/'.$request->id.'/approve';
        foreach (['0', '900.13', '800.123', '8e2'] as $amount) {
            $this->commandPost($url, ['approved_amount' => $amount])->assertUnprocessable();
        }
        foreach (['/refund-processes', '/refund-stages/1'] as $path) {
            $this->commandPost($this->base.$path, ['stage_id' => 0, 'refund_amount' => '800.12'])->assertUnprocessable();
        }
        $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertFalse($this->case->fresh()->is_completed);
    }
}
