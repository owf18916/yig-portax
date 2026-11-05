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

    public static function outcomes(): array
    {
        $rows = [];
        foreach ([4, 7, 10, 12] as $stage) foreach (['ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED'] as $outcome) $rows[$stage.' '.$outcome] = [$stage, $outcome];
        return $rows;
    }

    #[DataProvider('outcomes')]
    public function test_stage_outcome_round_trip_and_exact_replays(int $stage, string $outcome): void
    {
        $this->kian->update(['stage_id' => $stage]);
        $case = $this->case->fresh()->getAttributes();
        $this->postJson($this->base.'/record-response', $this->payload(['response_status' => $outcome]))->assertOk()
            ->assertJsonPath('data.status', 'responded')->assertJsonPath('data.final_response.outcome', $outcome)
            ->assertJsonPath('data.final_response.approved_amount', '100.01')
            ->assertJsonPath('data.final_response.recorded_by.id', $this->actor->id);
        $recorded = $this->snapshot();
        $document = $this->kian->fresh()->responseDocument;
        $this->assertSame((string) $stage, $document->stage_code);
        $this->assertSame($this->kian->id, (int) $document->documentable_id);
        $this->assertSame($this->case->id, (int) $document->tax_case_id);
        // Windows local adapters cannot report POSIX visibility reliably. The
        // security boundary is the private disk root and authorized downloads.
        $this->assertStringStartsWith(storage_path(), Storage::disk('local')->path($document->file_path));
        $this->assertStringNotContainsString(public_path(), Storage::disk('local')->path($document->file_path));
        $this->getJson('/api/documents/'.$document->id.'/download')->assertOk();
        $this->travel(3)->minutes();
        $this->postJson($this->base.'/record-response', $this->payload(['response_status' => $outcome]))->assertOk();
        $this->assertSame($recorded, $this->snapshot());
        $this->postJson($this->base.'/close')->assertOk()->assertJsonPath('data.status', 'closed');
        $closed = $this->snapshot();
        $this->travel(3)->minutes();
        $this->postJson($this->base.'/close')->assertOk();
        $this->postJson($this->base.'/record-response', $this->payload(['response_status' => $outcome]))->assertOk();
        $this->assertSame($closed, $this->snapshot());
        $read = $this->getJson('/api/tax-cases/'.$this->case->id.'/kian-submissions/'.$stage)->assertOk()
            ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.available_actions.close', false);
        $this->assertSame(['id', 'name'], array_keys($read->json('data.final_response.recorded_by')));
        $this->assertArrayNotHasKey('response_document', $read->json('data'));
        $this->assertArrayNotHasKey('file_path', $read->json('data.final_response.document'));
        $this->assertSame($case, $this->case->fresh()->getAttributes());
        foreach (['refund_processes', 'preliminary_refund_requests', 'revisions', 'workflow_histories', 'status_histories'] as $table) $this->assertDatabaseCount($table, 0);
        Queue::assertNothingPushed();
        $this->travelBack();
    }

    public static function amounts(): array
    {
        return ['null' => [null, null], 'zero' => ['0', '0.00'], 'cent' => ['0.01', '0.01'], 'ceiling' => ['100.01', '100.01']];
    }

    #[DataProvider('amounts')]
    public function test_exact_amount_including_null_and_zero(mixed $amount, ?string $expected): void
    {
        $this->postJson($this->base.'/record-response', $this->payload(['approved_amount' => $amount, 'response_status' => 'REJECTED']))->assertOk()->assertJsonPath('data.approved_amount', $expected);
        $this->assertSame($expected, $this->kian->fresh()->approved_amount);
        $this->postJson($this->base.'/close')->assertOk();
    }

    public static function invalidPayloads(): array
    {
        return array_map(fn ($pair) => [$pair], [
            ['approved_amount' => '-0.01'], ['approved_amount' => '100.02'], ['approved_amount' => '0.001'], ['approved_amount' => '1e1'], ['approved_amount' => 1.2], ['approved_amount' => '9999999999999999999'],
            ['response_status' => 'PENDING'], ['response_reference' => null], ['response_issued_date' => null], ['response_received_date' => 'invalid'], ['document' => null],
            ['responded_by' => 999], ['responded_at' => '2020-01-01'], ['recorded_by' => 999], ['closed_by' => 999], ['response_document_id' => 1], ['documentable_id' => 0], ['documentable_type' => KianSubmission::class], ['stage_id' => 7],
        ]);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_or_forged_response_is_unchanged(array $replace): void
    {
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload($replace))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public static function historical(): array
    {
        return [['draft', 4], ['approved', 4], ['rejected', 4], ['submitted', null], ['submitted', 5]];
    }

    #[DataProvider('historical')]
    public function test_invalid_predecessors_and_legacy_are_read_only(string $status, ?int $stage): void
    {
        $this->kian->update(['status' => $status, 'stage_id' => $stage]);
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload())->assertUnprocessable();
        $this->postJson($this->base.'/close')->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        if ($stage === 4) $this->getJson('/api/tax-cases/'.$this->case->id.'/kian-submissions/4')->assertOk()->assertJsonPath('data.final_response', null);
    }

    public static function conflicts(): array
    {
        return [[['response_status' => 'REJECTED']], [['response_reference' => 'OTHER']], [['approved_amount' => '0']], [['approved_amount' => null]], [['response_issued_date' => '2026-10-01']], [['response_received_date' => '2026-10-01']], [['response_notes' => 'Other']]];
    }

    #[DataProvider('conflicts')]
    public function test_conflicting_replays_before_and_after_close_do_not_mutate(array $replace): void
    {
        $this->postJson($this->base.'/record-response', $this->payload())->assertOk();
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload($replace))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->postJson($this->base.'/close')->assertOk();
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload($replace))->assertUnprocessable();
        $this->postJson($this->base.'/close', ['closed_by' => 999])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_pdf_type_size_conflicts_and_generic_mutation_protections(): void
    {
        $before = $this->snapshot();
        foreach ([$this->pdf('valid', 'not.txt'), UploadedFile::fake()->createWithContent('bad.pdf', 'plain text'), UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')] as $file) {
            $this->postJson($this->base.'/record-response', $this->payload(['document' => $file]))->assertUnprocessable();
            $this->assertSame($before, $this->snapshot());
        }
        $this->postJson($this->base.'/record-response', $this->payload())->assertOk();
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload(['document' => $this->pdf('different')]))->assertUnprocessable();
        $document = $this->kian->fresh()->responseDocument;
        $this->deleteJson('/api/documents/'.$document->id)->assertUnprocessable();
        $this->postJson('/api/documents', ['file' => $this->pdf(), 'tax_case_id' => $this->case->id, 'stage_code' => '4', 'documentable_type' => KianSubmission::class, 'documentable_id' => 0, 'document_type' => 'kian_final_response'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        try {
            $document->update(['documentable_id' => 0]);
            $this->fail('Evidence must be immutable.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
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
        $this->postJson($this->base.'/record-response', $this->payload())->assertOk();
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

    public function test_storage_and_model_failures_compensate_and_sanitize_errors(): void
    {
        $before = $this->snapshot();
        $disk = Storage::disk('local');
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->app['events']->listen('eloquent.updating: '.KianSubmission::class, function ($kian) {
            if ($kian->status === 'responded') throw new \RuntimeException('sensitive internal database failure');
        });
        $response = $this->postJson($this->base.'/record-response', $this->payload())->assertStatus(500);
        $this->assertStringNotContainsString('sensitive', $response->getContent());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_storage_failure_has_no_metadata_or_status_mutation(): void
    {
        $before = $this->snapshot();
        $disk = Storage::disk('local');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::set('local', $mock);
        $this->postJson($this->base.'/record-response', $this->payload())->assertStatus(500);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_closed_case_refuses_writes_and_same_authorized_different_actor_retry_preserves_provenance(): void
    {
        $this->postJson($this->base.'/close')->assertUnprocessable();
        $this->postJson($this->base.'/record-response', $this->payload())->assertOk();
        $other = User::factory()->create(['entity_id' => $this->actor->entity_id, 'role_id' => $this->actor->role_id, 'is_active' => true]);
        $this->actingAs($other);
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload())->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->case->update(['is_completed' => true]);
        $before = $this->snapshot();
        $this->postJson($this->base.'/close')->assertUnprocessable();
        $this->postJson($this->base.'/record-response', $this->payload())->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stage_reads_are_bounded_and_do_not_depend_on_current_exposure(): void
    {
        $this->postJson($this->base.'/record-response', $this->payload())->assertOk();
        foreach ([7, 10, 12] as $stage) {
            $copy = $this->kian->replicate();
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

    public function test_holding_actor_can_record_and_close_and_null_zero_retry_is_not_equal(): void
    {
        $holding = Entity::create(['code' => 'KHOLD', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'KHOLD']);
        $this->actor->update(['entity_id' => $holding->id]);
        $this->actingAs($this->actor->fresh());
        $this->postJson($this->base.'/record-response', $this->payload(['approved_amount' => null]))->assertOk();
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload(['approved_amount' => '0.00']))->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->postJson($this->base.'/close')->assertOk();
    }

    public function test_missing_or_wrongly_owned_final_evidence_cannot_close_or_download(): void
    {
        $this->postJson($this->base.'/record-response', $this->payload())->assertOk();
        $document = $this->kian->fresh()->responseDocument;
        // Simulate preexisting corruption without weakening immutable model events.
        DB::table('documents')->where('id', $document->id)->update(['stage_code' => '7']);
        $before = $this->snapshot();
        $this->postJson($this->base.'/close')->assertUnprocessable();
        $this->getJson('/api/documents/'.$document->id.'/download')->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        DB::table('documents')->where('id', $document->id)->update(['stage_code' => '4']);
        Storage::disk('local')->delete($document->file_path);
        $before = $this->snapshot();
        $this->postJson($this->base.'/close')->assertUnprocessable();
        $this->postJson($this->base.'/record-response', $this->payload())->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_revision_document_commands_refuse_final_evidence(): void
    {
        $this->postJson($this->base.'/record-response', $this->payload())->assertOk();
        $document = $this->kian->fresh()->response_document_id;
        $before = $this->snapshot();
        $revision = new \App\Models\Revision(['stage_code' => 4, 'proposed_document_changes' => ['files_to_delete' => [$document], 'files_to_add' => []]]);
        $service = app(\App\Services\RevisionService::class);
        try {
            (new \ReflectionMethod($service, 'applyDocuments'))->invoke($service, $revision, $this->case, $this->case);
            $this->fail('Revision must refuse final evidence.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('documents', $exception->errors());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_preliminary_boundary_remains_read_only_and_does_not_stage_files(): void
    {
        $this->case->update(['spt_type' => 'Pengembalian Pendahuluan']);
        $before = $this->snapshot();
        $this->postJson($this->base.'/record-response', $this->payload())->assertUnprocessable();
        $this->postJson($this->base.'/close')->assertUnprocessable();
        $this->getJson('/api/tax-cases/'.$this->case->id.'/kian-submissions/4')->assertOk()
            ->assertJsonPath('data.available_actions.record_response', false)->assertJsonPath('data.available_actions.close', false);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_partial_file_write_failure_removes_only_its_staged_file(): void
    {
        $before = $this->snapshot();
        $disk = Storage::disk('local');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('putFileAs')->once()->andReturnUsing(function ($directory, $file, $name) use ($disk) {
            $disk->put($directory.'/'.$name, 'partial private file');
            throw new \RuntimeException('Partial storage failure');
        });
        Storage::set('local', $mock);
        $this->postJson($this->base.'/record-response', $this->payload())->assertStatus(500);
        $this->assertSame($before, $this->snapshot());
    }
}
