<?php

namespace Tests\Feature;

use App\Models\{CaseStatus, Currency, Document, Entity, FiscalYear, KianSubmission, Role, TaxCase, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Auth, DB, Queue, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KianFinalResponseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private TaxCase $case;
    private User $actor;
    private KianSubmission $kian;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);
        $this->assertStringStartsWith(sys_get_temp_dir(), storage_path());
        Queue::fake();
        Storage::fake('local');
        $entity = Entity::create(['code' => 'KFN', 'name' => 'KIAN entity', 'entity_type' => 'AFFILIATE', 'tax_id' => 'KFN']);
        $role = Role::create(['code' => 'KSTAFF', 'name' => 'Recording staff', 'permissions' => ['submit_cases'], 'is_active' => true]);
        $this->actor = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        $status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        $this->case = TaxCase::create(['entity_id' => $entity->id, 'user_id' => $this->actor->id, 'fiscal_year_id' => $year->id, 'case_number' => 'KIAN-FINAL', 'case_type' => 'VAT', 'case_status_id' => $status->id, 'currency_id' => $currency->id, 'spt_position_type' => 'LB', 'spt_type' => 'Restitusi', 'reported_amount' => '1000.00', 'disputed_amount' => '1000.00', 'current_stage' => 4]);
        $this->kian = KianSubmission::create(['tax_case_id' => $this->case->id, 'stage_id' => 4, 'kian_number' => 'K-1', 'submission_date' => '2026-11-01', 'kian_amount' => '100.01', 'status' => 'submitted', 'submitted_by' => $this->actor->id, 'submitted_at' => now()]);
        $this->base = '/api/tax-cases/'.$this->case->id.'/kian-submissions/'.$this->kian->id;
        $this->actingAs($this->actor);
    }

    private function pdf(string $content = 'decision', string $name = 'decision.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n".$content."\n%%EOF");
    }

    private function payload(array $replace = []): array
    {
        return array_replace(['response_status' => 'ACCEPTED', 'response_reference' => 'OFFICE-2026-01', 'response_issued_date' => '2026-11-02', 'response_received_date' => '2026-11-03', 'approved_amount' => '100.01', 'response_notes' => 'Final disposition', 'document' => $this->pdf()], $replace);
    }

    private function snapshot(): array
    {
        return [$this->kian->fresh()->getAttributes(), Document::withTrashed()->get()->toArray(), Storage::disk('local')->allFiles(), $this->case->fresh()->getAttributes()];
    }

    // Synthetic pre-existing history, never created through the retired command.
    private function historicalEvidence(string $status = 'responded'): void
    {
        $path = 'history/response.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nhistorical\n%%EOF");
        $document = Document::create([
            'tax_case_id' => $this->case->id, 'documentable_type' => KianSubmission::class,
            'documentable_id' => $this->kian->id, 'stage_code' => (string) $this->kian->stage_id,
            'document_type' => 'kian_final_response', 'original_filename' => 'response.pdf',
            'file_path' => $path, 'file_size' => 30, 'file_mime_type' => 'application/pdf',
            'hash' => hash('sha256', 'history'), 'uploaded_by' => $this->actor->id,
            'uploaded_at' => now(), 'status' => 'ACTIVE',
        ]);
        $this->kian->forceFill([
            'status' => $status, 'response_status' => 'REJECTED', 'response_reference' => 'OFFICE-2026-01',
            'response_issued_date' => '2026-11-02', 'response_received_date' => '2026-11-03',
            'approved_amount' => '0.00', 'response_document_id' => $document->id,
            'responded_by' => $this->actor->id, 'responded_at' => now(),
            'closed_by' => $status === 'closed' ? $this->actor->id : null,
            'closed_at' => $status === 'closed' ? now() : null,
        ])->save();
    }

    public static function stagesAndStatuses(): array
    {
        $rows = [];
        foreach ([4, 7, 10, 12] as $stage) foreach (['draft', 'submitted', 'responded', 'closed', 'approved', 'rejected'] as $status) $rows[] = [$stage, $status];
        return $rows;
    }

    #[DataProvider('stagesAndStatuses')]
    public function test_retired_commands_preserve_submissions_and_historical_reads(int $stage, string $status): void
    {
        $this->kian->update(['stage_id' => $stage]);
        if (in_array($status, ['responded', 'closed'], true)) $this->historicalEvidence($status);
        else $this->kian->update(['status' => $status]);
        $before = $this->snapshot();
        for ($retry = 0; $retry < 2; $retry++) {
            $this->postJson($this->base.'/record-response', $this->payload())->assertStatus(410)
                ->assertJsonPath('message', 'KIAN response and closure actions are retired. Submission completes the internal KIAN process.');
            $this->postJson($this->base.'/close', ['closed_by' => 999])->assertStatus(410);
            $read = $this->getJson('/api/tax-cases/'.$this->case->id.'/kian-submissions/'.$stage)->assertOk()
                ->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.available_actions', ['record_response' => false, 'close' => false]);
            if (in_array($status, ['responded', 'closed'], true)) {
                $read->assertJsonPath('data.final_response.approved_amount', '0.00');
                $this->getJson('/api/documents/'.$this->kian->fresh()->response_document_id.'/download')->assertOk();
                $this->assertArrayNotHasKey('file_path', $read->json('data.final_response.document'));
                $this->assertSame(['id', 'name'], array_keys($read->json('data.final_response.recorded_by')));
            }
        }
        $this->assertSame($before, $this->snapshot());
        Queue::assertNothingPushed();
    }

    public function test_service_entry_points_are_retired_without_writes(): void
    {
        $before = $this->snapshot();
        foreach (['record', 'close'] as $command) {
            try {
                $service = app(\App\Services\KianLifecycle::class);
                $command === 'record' ? $service->record($this->case->id, $this->kian->id, [], $this->pdf()) : $service->close($this->case->id, $this->kian->id);
                $this->fail('Retired service must deny writes.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(410, $exception->getStatusCode());
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function deniedActors(): array
    {
        return [['inactive'], ['inactive_role'], ['permission'], ['foreign'], ['missing_role']];
    }

    #[DataProvider('deniedActors')]
    public function test_denied_actors_cannot_write_or_read_evidence(string $kind): void
    {
        $this->historicalEvidence();
        $document = $this->kian->fresh()->response_document_id;
        if ($kind === 'inactive') $this->actor->update(['is_active' => false]);
        if ($kind === 'inactive_role') $this->actor->role()->update(['is_active' => false]);
        if ($kind === 'permission') $this->actor->role()->update(['permissions' => []]);
        if ($kind === 'missing_role') $this->actor->update(['role_id' => null]);
        if ($kind === 'foreign') {
            $other = Entity::create(['code' => 'OTHER', 'name' => 'Foreign', 'entity_type' => 'AFFILIATE', 'tax_id' => 'OTHER']);
            $this->actor->update(['entity_id' => $other->id]);
        }
        $this->actingAs($this->actor->fresh());
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload())->assertForbidden();
        $this->postJson($this->base.'/close')->assertForbidden();
        $this->getJson('/api/documents/'.$document.'/download')->assertForbidden();
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_foreign_child_guest_and_real_csrf_refuse_without_changes(): void
    {
        $other = $this->case->replicate();
        $other->case_number = 'SECOND';
        $other->save();
        $before = $this->snapshot();
        foreach (['record-response', 'close'] as $command) $this->postJson('/api/tax-cases/'.$other->id.'/kian-submissions/'.$this->kian->id.'/'.$command, $command === 'close' ? [] : $this->payload())->assertNotFound();
        (new \ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);
        $this->postJson($this->base.'/record-response', $this->payload())->assertStatus(419);
        $this->postJson($this->base.'/close')->assertStatus(419);
        Auth::forgetGuards();
        $this->postJson($this->base.'/record-response', $this->payload())->assertUnauthorized();
        $this->postJson($this->base.'/close')->assertUnauthorized();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stage_reads_are_bounded_and_do_not_depend_on_current_exposure(): void
    {
        $this->historicalEvidence();
        foreach ([7, 10, 12] as $stage) {
            $copy = $this->kian->replicate();
            foreach (KianSubmission::FINAL_FIELDS as $field) $copy->$field = null;
            $copy->status = 'submitted';
            $copy->stage_id = $stage;
            $copy->save();
        }
        $this->case->update(['disputed_amount' => '0.00', 'current_stage' => 12]);
        $counts = [];
        foreach ([4, 7, 10, 12] as $stage) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $read = $this->getJson('/api/tax-cases/'.$this->case->id.'/kian-submissions/'.$stage)->assertOk();
            $counts[] = count(DB::getQueryLog());
            DB::disableQueryLog();
            $this->assertStringNotContainsString('password', $read->getContent());
            $this->assertStringNotContainsString('file_path', $read->getContent());
            if ($stage === 4) $read->assertJsonPath('data.final_response.reference', 'OFFICE-2026-01');
            else $read->assertJsonPath('data.final_response', null);
        }
        $this->assertLessThanOrEqual(12, max($counts));
        $this->assertLessThanOrEqual(3, max($counts) - min($counts));
    }

    public function test_migration_preserves_nullable_history_and_lifetime_stage_uniqueness(): void
    {
        foreach (['draft', 'submitted', 'approved', 'rejected'] as $index => $status) {
            $row = $this->kian->replicate();
            $row->stage_id = null;
            $row->status = $status;
            $row->save();
            foreach (KianSubmission::FINAL_FIELDS as $field) $this->assertNull($row->fresh()->$field);
        }
        $this->assertCount(11, array_intersect(KianSubmission::FINAL_FIELDS, \Illuminate\Support\Facades\Schema::getColumnListing('kian_submissions')));
        $this->kian->delete();
        $copy = $this->kian->replicate();
        $copy->deleted_at = null;
        try {
            $copy->save();
            $this->fail('A soft-deleted stage must remain reserved.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('UNIQUE', $exception->getMessage());
        }
    }

    public function test_historical_evidence_cannot_be_deleted_reassigned_or_uploaded_generically(): void
    {
        $this->historicalEvidence();
        $before = $this->snapshot();
        $document = $this->kian->fresh()->responseDocument;
        $this->deleteJson('/api/documents/'.$document->id)->assertUnprocessable();
        $this->postJson('/api/documents', ['file' => $this->pdf(), 'tax_case_id' => $this->case->id, 'stage_code' => '4', 'documentable_type' => KianSubmission::class, 'documentable_id' => 0, 'document_type' => 'kian_final_response'])->assertUnprocessable();
        try {
            $document->update(['documentable_id' => 0]);
            $this->fail('Historical evidence must remain immutable.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
    }

}
