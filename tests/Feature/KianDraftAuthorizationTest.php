<?php

namespace Tests\Feature;

use App\Models\{CaseStatus, Currency, Document, Entity, FiscalYear, KianSubmission, Role, TaxCase, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KianDraftAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private TaxCase $case;
    private User $actor;
    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);
        $this->assertStringStartsWith(sys_get_temp_dir(), storage_path());
        Queue::fake();
        Storage::fake('local');
        $entity = Entity::create(['code' => 'KD', 'name' => 'Draft entity', 'entity_type' => 'AFFILIATE', 'tax_id' => 'KD']);
        $role = Role::create(['code' => 'KD', 'name' => 'Writer', 'permissions' => ['submit_cases'], 'is_active' => true]);
        $this->actor = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        $status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        $this->case = TaxCase::create(['entity_id' => $entity->id, 'user_id' => $this->actor->id, 'fiscal_year_id' => $year->id, 'case_number' => 'KD', 'case_type' => 'VAT', 'case_status_id' => $status->id, 'currency_id' => $currency->id, 'spt_position_type' => 'LB', 'spt_type' => 'Restitusi', 'reported_amount' => '1000.00', 'disputed_amount' => '1000.00', 'current_stage' => 12]);
        $this->case->skpRecord()->create(['skp_number' => 'SKP', 'skp_type' => 'NIHIL', 'skp_amount' => '0']);
        foreach (['objectionDecision', 'appealDecision', 'supremeCourtDecision'] as $relation) {
            $this->case->$relation()->create(['decision_number' => $relation, 'decision_date' => '2026-11-01', 'decision_type' => 'rejected', 'decision_amount' => '0']);
        }
        $this->url = '/api/tax-cases/'.$this->case->id;
        $this->actingAs($this->actor);
    }

    private function payload(): array
    {
        return ['kian_number' => 'K-DRAFT', 'submission_date' => '2026-11-01', 'notes' => 'Changed'];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['tax_cases', 'kian_submissions', 'documents', 'workflow_histories', 'status_histories', 'audit_logs', 'notification_logs', 'notification_attempts', 'refund_processes', 'skp_records', 'objection_decisions', 'appeal_decisions', 'supreme_court_decisions'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $snapshot['files'] = collect(Storage::disk('local')->allFiles())->mapWithKeys(fn ($path) => [$path => hash('sha256', Storage::disk('local')->get($path))])->all();
        return $snapshot;
    }

    public static function denied(): array
    {
        $rows = [];
        foreach ([4, 7, 10, 12] as $stage) foreach (['capability', 'inactive-user', 'inactive-role', 'missing-role', 'foreign', 'holding-no-capability'] as $kind) $rows[$stage.' '.$kind] = [$stage, $kind];
        return $rows;
    }

    private function restrict(string $kind): void
    {
        if (in_array($kind, ['capability', 'holding-no-capability'], true)) $this->actor->role->update(['permissions' => []]);
        if ($kind === 'inactive-user') $this->actor->update(['is_active' => false]);
        if ($kind === 'inactive-role') $this->actor->role->update(['is_active' => false]);
        if ($kind === 'missing-role') $this->actor->update(['role_id' => null]);
        if ($kind === 'foreign') {
            $entity = Entity::create(['code' => 'OTHER', 'name' => 'Other', 'entity_type' => 'AFFILIATE', 'tax_id' => 'OTHER']);
            $this->actor->update(['entity_id' => $entity->id]);
        }
        if ($kind === 'holding-no-capability') $this->actor->entity->update(['entity_type' => 'HOLDING']);
        $this->actingAs($this->actor->fresh());
    }

    #[DataProvider('denied')]
    public function test_every_denied_writer_is_mutation_free(int $stage, string $kind): void
    {
        Storage::disk('local')->put('draft-evidence.pdf', '%PDF-1.4 retained');
        $document = Document::create(['tax_case_id' => $this->case->id, 'documentable_type' => KianSubmission::class, 'documentable_id' => 0, 'stage_code' => (string) $stage, 'document_type' => 'supporting_document', 'original_filename' => 'draft.pdf', 'file_path' => 'draft-evidence.pdf', 'file_mime_type' => 'application/pdf', 'file_size' => 17, 'uploaded_by' => $this->actor->id, 'uploaded_at' => now(), 'status' => 'DRAFT']);
        $this->restrict($kind);
        $before = $this->snapshot();
        $this->postJson($this->url.'/kian-submissions/'.$stage, $this->payload())->assertForbidden();
        $this->postJson($this->url.'/kian-submissions', $this->payload() + ['stage_id' => $stage, 'action' => 'submit'])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        if ($kind !== 'foreign') $this->getJson($this->url.'/kian-submissions/'.$stage)->assertOk()->assertJsonPath('data', null)->assertJsonPath('draft_actions.create', false);
        $draft = KianSubmission::create($this->payload() + ['tax_case_id' => $this->case->id, 'stage_id' => $stage, 'status' => 'draft', 'kian_amount' => '1000']);
        $before = $this->snapshot();
        foreach (['save', 'submit'] as $action) {
            $this->postJson($this->url.'/kian-submissions/'.$stage, $this->payload() + ['action' => $action])->assertForbidden();
            $this->postJson($this->url.'/kian-submissions', $this->payload() + ['stage_id' => $stage, 'action' => $action])->assertForbidden();
        }
        $this->putJson($this->url.'/kian-submissions/'.$stage, $this->payload())->assertForbidden();
        $this->postJson($this->url.'/kian-submissions/'.$draft->id.'/submit')->assertForbidden();
        $this->putJson($this->url.'/next-action/kian-submissions/'.$draft->id, ['next_action' => 'Changed'])->assertForbidden();
        $this->deleteJson('/api/documents/'.$document->id)->assertForbidden();
        $this->postJson('/api/documents', ['tax_case_id' => $this->case->id, 'documentable_type' => KianSubmission::class, 'documentable_id' => $draft->id, 'stage_code' => (string) $stage, 'document_type' => 'supporting_document', 'file' => UploadedFile::fake()->createWithContent('draft.pdf', '%PDF-1.4 test')])->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        if ($kind !== 'foreign') {
            $this->getJson($this->url.'/kian-submissions/'.$stage)->assertOk()->assertJsonPath('draft_actions', ['create' => false, 'save_draft' => false, 'submit' => false]);
            $this->getJson($this->url)->assertOk()->assertJsonPath('data.can_create_kian', false)->assertJsonPath('data.kian_status_by_stage.'.$stage.'.draft_actions.submit', false);
        }
    }

    public static function stages(): array
    {
        $rows = [];
        foreach ([4, 7, 10, 12] as $stage) foreach ([false, true] as $holding) foreach ([false, true] as $storeSubmit) $rows[] = [$stage, $holding, $storeSubmit];
        return $rows;
    }

    #[DataProvider('stages')]
    public function test_authorized_same_entity_and_holding_round_trip(int $stage, bool $holding, bool $storeSubmit): void
    {
        if ($holding) {
            $entity = Entity::create(['code' => 'HOLD', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'HOLD']);
            $this->actor->update(['entity_id' => $entity->id]);
            $this->actingAs($this->actor->fresh());
        }
        $url = $this->url.'/kian-submissions/'.$stage;
        $this->getJson($url)->assertOk()->assertJsonPath('data', null)->assertJsonPath('draft_actions.create', true);
        $this->postJson($url, $this->payload())->assertCreated();
        $this->putJson($url, $this->payload() + ['action' => 'submit'])->assertOk()->assertJsonPath('data.status', 'draft');
        $draft = KianSubmission::sole();
        $this->getJson($url)->assertOk()->assertJsonPath('draft_actions.create', false)->assertJsonPath('draft_actions.save_draft', true);
        $this->postJson($this->url.'/kian-submissions', $this->payload() + ['stage_id' => $stage])->assertOk()->assertJsonPath('data.status', 'draft');
        if ($storeSubmit) $this->postJson($url, $this->payload() + ['action' => 'submit'])->assertOk()->assertJsonPath('data.status', 'submitted');
        else $this->postJson($this->url.'/kian-submissions/'.$draft->id.'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->getJson($url)->assertOk()->assertJsonPath('draft_actions.submit', false);
        $before = $this->snapshot();
        $this->postJson($url, $this->payload())->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_nested_identity_legacy_and_exposure_guards(): void
    {
        $this->postJson($this->url.'/kian-submissions', $this->payload() + ['stage_id' => 4])->assertCreated();
        $draft = KianSubmission::sole();
        $other = $this->case->replicate(); $other->case_number = 'OTHER'; $other->save();
        $before = $this->snapshot();
        $this->postJson('/api/tax-cases/'.$other->id.'/kian-submissions/'.$draft->id.'/submit')->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        $draft->update(['stage_id' => null]);
        $before = $this->snapshot();
        $this->postJson($this->url.'/kian-submissions/'.$draft->id.'/submit')->assertStatus(400);
        $this->assertSame($before, $this->snapshot());
        $this->case->skpRecord->update(['skp_type' => 'LB', 'skp_amount' => '1000']);
        $before = $this->snapshot();
        $this->postJson($this->url.'/kian-submissions/4', $this->payload())->assertUnprocessable();
        $this->getJson($this->url.'/kian-submissions/4')->assertOk()->assertJsonPath('draft_actions.create', false);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_projection_reuses_loaded_relations_without_queries(): void
    {
        $this->actor->load(['role', 'entity']);
        $this->case->getKianStatusByStage();
        DB::enableQueryLog(); DB::flushQueryLog();
        foreach (range(1, 4) as $unused) $this->case->getKianStatusByStage();
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_guests_and_genuine_csrf_verifier_deny_all_command_shapes(): void
    {
        $this->postJson($this->url.'/kian-submissions/4', $this->payload())->assertCreated();
        $id = KianSubmission::sole()->id;
        $before = $this->snapshot();
        $commands = [['POST', '/kian-submissions'], ['POST', '/kian-submissions/4'], ['PUT', '/kian-submissions/4'], ['POST', '/kian-submissions/'.$id.'/submit'], ['PUT', '/next-action/kian-submissions/'.$id]];
        (new \ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);
        foreach ($commands as [$method, $path]) $this->json($method, $this->url.$path, $this->payload())->assertStatus(419);
        $this->assertSame($before, $this->snapshot());
        \Illuminate\Support\Facades\Auth::forgetGuards();
        foreach ($commands as [$method, $path]) $this->json($method, $this->url.$path, $this->payload())->assertUnauthorized();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_absent_and_draft_stage_read_queries_are_bounded(): void
    {
        $counts = [];
        foreach ([4, 7, 10, 12] as $stage) {
            foreach ([false, true] as $draft) {
                if ($draft) KianSubmission::create($this->payload() + ['tax_case_id' => $this->case->id, 'stage_id' => $stage, 'status' => 'draft', 'kian_amount' => '1000']);
                $this->actingAs($this->actor->fresh());
                DB::enableQueryLog(); DB::flushQueryLog();
                $this->getJson($this->url.'/kian-submissions/'.$stage)->assertOk()->assertJsonPath('draft_actions.submit', true);
                $counts[] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
        }
        $this->assertLessThanOrEqual(12, max($counts));
        $this->assertLessThanOrEqual(2, max($counts) - min($counts));
    }
}
