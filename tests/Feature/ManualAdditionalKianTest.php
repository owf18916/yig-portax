<?php

namespace Tests\Feature;

use App\Models\{CaseStatus, Currency, Document, Entity, FiscalYear, KianSubmission, Role, TaxCase, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Queue, Storage};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualAdditionalKianTest extends TestCase
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
            $this->case->$relation()->create(['decision_number' => $relation, 'decision_date' => '2026-11-01', 'decision_type' => 'rejected', 'decision_amount' => '0', 'status' => 'submitted']);
        }
        $this->url = '/api/tax-cases/'.$this->case->id;
        $this->actingAs($this->actor);
    }

    private function payload(): array
    {
        return ['kian_number' => 'K-DRAFT', 'submission_date' => '2026-11-01', 'notes' => 'Changed', 'loss_amount' => '50000.01'];
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

    public static function origins(): array
    {
        $rows = [];
        foreach ([7, 10, 12] as $stage) foreach (['unresolved', 'zero', 'positive', 'partial'] as $exposure) foreach ([true, false] as $store) $rows[] = [$stage, $exposure, $store];
        return $rows;
    }

    private function signed(int $stage, int $id = 0): void
    {
        $this->postJson('/api/documents', ['tax_case_id' => $this->case->id, 'stage_code' => (string) $stage,
            'documentable_type' => KianSubmission::class, 'documentable_id' => $id, 'document_type' => 'supporting_document',
            'file' => UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4 signed internal KIAN")])->assertCreated();
    }

    #[DataProvider('origins')]
    public function test_optional_manual_round_trip_without_exposure_dependency(int $stage, string $exposure, bool $store): void
    {
        if ($exposure === 'unresolved') $this->case->update(['spt_position_type' => null]);
        if ($exposure === 'zero') $this->case->objectionDecision->update(['decision_type' => 'granted']);
        if ($exposure === 'partial') $this->case->objectionDecision->update(['decision_type' => 'partially_granted', 'decision_amount' => '999999.99']);
        $before = $this->snapshot();
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.kian_status_by_stage.'.$stage.'.draft_actions.create', true);
        $this->assertDatabaseCount('kian_submissions', 0);
        $url = $this->url.'/kian-submissions/'.$stage;
        $payload = $this->payload(); $payload['loss_amount'] = '50000.00';
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.kian_amount', '50000.00')->assertJsonPath('data.loss_amount', '50000.00');
        $this->postJson($url, $payload + ['action' => 'submit'])->assertUnprocessable();
        $this->assertSame('draft', KianSubmission::sole()->status);
        $payload['loss_amount'] = '70000.19';
        $this->putJson($url, $payload)->assertOk();
        $draft = KianSubmission::sole();
        $this->getJson($url)->assertOk()->assertJsonPath('data.kian_amount', '70000.19');
        $this->signed($stage, $store ? 0 : $draft->id);
        if ($store) $this->postJson($url, $payload + ['action' => 'submit'])->assertOk();
        else $this->postJson($this->url.'/kian-submissions/'.$draft->id.'/submit', ['loss_amount' => '1.00'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.kian_amount', '70000.19')->assertJsonPath('data.loss_amount', '70000.19')->assertJsonPath('draft_actions.submit', false);
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.kian_status_by_stage.'.$stage.'.lossAmount', '70000.19');
        $submitted = $this->snapshot();
        $this->postJson($url, $this->payload() + ['action' => 'submit'])->assertUnprocessable();
        $this->postJson($this->url.'/kian-submissions/'.$draft->id.'/submit')->assertStatus(400);
        $this->deleteJson('/api/documents/'.Document::sole()->id)->assertUnprocessable();
        $this->assertSame($submitted, $this->snapshot());
        foreach (['tax_cases', 'workflow_histories', 'status_histories', 'notification_logs', 'notification_attempts', 'refund_processes', 'skp_records', 'objection_decisions', 'appeal_decisions', 'supreme_court_decisions'] as $table) $this->assertSame($before[$table], $submitted[$table], $table);
        $this->assertDatabaseCount('kian_submissions', 1);
    }

    public static function money(): array
    {
        return array_map(fn ($value) => [$value], ['0', '0.01', '0050000.00', '999999999.99', '-1', '1.001', '1.000', '1e5', 'NaN', 'INF', '', null, 5, [], '1000000000000000000.00']);
    }

    #[DataProvider('money')]
    public function test_exact_money_validation_is_shared_by_create_and_update(mixed $value): void
    {
        $valid = is_string($value) && preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) && strlen(explode('.', ltrim($value, '0'))[0]) <= 18;
        $payload = $this->payload(); $payload['loss_amount'] = $value;
        $url = $this->url.'/kian-submissions/7';
        $before = $this->snapshot();
        $response = $this->postJson($url, $payload);
        if ($valid) {
            $response->assertCreated();
            $canonical = KianSubmission::sole()->kian_amount;
            $this->assertSame($canonical, KianSubmission::sole()->loss_amount);
            $this->getJson($url)->assertOk()->assertJsonPath('data.kian_amount', $canonical);
            $this->signed(7);
            $this->postJson($url, $payload + ['action' => 'submit'])->assertOk()->assertJsonPath('data.kian_amount', $canonical);
        } else {
            $response->assertUnprocessable(); $this->assertSame($before, $this->snapshot());
            $this->postJson($url, $this->payload())->assertCreated();
            $before = $this->snapshot();
            $this->putJson($url, $payload)->assertUnprocessable();
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_optional_origins_require_canonical_completion_and_scoped_stage(): void
    {
        foreach ([7 => 'objectionDecision', 10 => 'appealDecision', 12 => 'supremeCourtDecision'] as $stage => $relation) {
            $this->case->$relation->update(['status' => 'draft']);
            $before = $this->snapshot();
            $this->postJson($this->url.'/kian-submissions/'.$stage, $this->payload())->assertUnprocessable();
            $this->getJson($this->url.'/kian-submissions/'.$stage)->assertOk()->assertJsonPath('draft_actions.create', false);
            $this->assertSame($before, $this->snapshot());
            $this->case->workflowHistories()->create(['stage_id' => $stage, 'status' => 'submitted', 'user_id' => $this->actor->id, 'action' => 'submitted']);
            $this->postJson($this->url.'/kian-submissions/'.$stage, $this->payload())->assertCreated();
        }
        foreach ([null, 8, '7evil', [7]] as $stage) $this->postJson($this->url.'/kian-submissions', $this->payload() + ['stage_id' => $stage])->assertUnprocessable();
        $this->postJson($this->url.'/kian-submissions/4', array_replace($this->payload(), ['kian_amount' => '1.00']))->assertUnprocessable();
    }

    public function test_money_normalization_retains_full_schema_precision_without_float_conversion(): void
    {
        $method = new \ReflectionMethod(\App\Http\Controllers\Api\KianSubmissionController::class, 'manualAmount');
        $this->assertSame('999999999999999999.99', $method->invoke(new \App\Http\Controllers\Api\KianSubmissionController, ['loss_amount' => '999999999999999999.99']));
    }

    public function test_lossy_sqlite_storage_rolls_back_instead_of_silently_rounding(): void
    {
        $before = $this->snapshot();
        $this->postJson($this->url.'/kian-submissions/7', array_replace($this->payload(), ['loss_amount' => '999999999999999999.99']))
            ->assertUnprocessable()->assertJsonValidationErrors('loss_amount');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_optional_absence_leaves_existing_downstream_access_available(): void
    {
        foreach ([7, 10] as $origin) {
            $this->case->workflowHistories()->delete();
            foreach (['objectionDecision' => 7, 'appealDecision' => 10, 'supremeCourtDecision' => 12] as $relation => $stage) {
                $this->case->$relation->update(['status' => $stage <= $origin ? 'submitted' : 'draft', 'continue_to_next_stage' => true]);
            }
            foreach (range(1, $origin) as $stage) $this->case->workflowHistories()->create(['stage_id' => $stage, 'user_id' => $this->actor->id, 'status' => 'submitted', 'action' => 'submitted']);
            // Keep the independent Stage 4 requirement satisfied, with no optional KIAN.
            KianSubmission::firstOrCreate(['tax_case_id' => $this->case->id, 'stage_id' => 4], ['kian_number' => 'BASELINE', 'submission_date' => '2026-11-01', 'kian_amount' => '10.00', 'status' => 'submitted']);
            $state = app(\App\Services\MainWorkflowStateResolver::class)->resolve($this->case->fresh());
            $this->assertContains($origin + 1, $state['available_stages']);
            $this->assertSame(0, KianSubmission::whereIn('stage_id', [7, 10, 12])->count());
        }
    }

    public function test_stage_four_manual_money_is_independent_from_required_exposure(): void
    {
        $this->signed(4);
        $this->postJson($this->url.'/kian-submissions/4', $this->payload() + ['action' => 'submit'])
            ->assertCreated()->assertJsonPath('data.kian_amount', '50000.01')->assertJsonPath('data.loss_amount', '50000.01');
        $this->assertSame('1000.00', $this->case->fresh()->exposureByStage()[4]['remainingExposure']);
    }

    public function test_conflicting_completion_and_foreign_history_cannot_unlock_optional_origin(): void
    {
        $other = $this->case->replicate(); $other->case_number = 'FOREIGN-HISTORY'; $other->save();
        $other->workflowHistories()->create(['stage_id' => 7, 'user_id' => $this->actor->id, 'status' => 'submitted', 'action' => 'submitted']);
        $this->case->objectionDecision->update(['status' => 'draft']);
        $before = $this->snapshot();
        $this->postJson($this->url.'/kian-submissions/7', $this->payload())->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        $this->case->objectionDecision->update(['status' => 'submitted']);
        $this->case->workflowHistories()->create(['stage_id' => 7, 'user_id' => $this->actor->id, 'status' => 'draft', 'action' => 'submitted']);
        $this->postJson($this->url.'/kian-submissions/7', $this->payload())->assertUnprocessable();
    }

    public function test_another_origins_signed_document_cannot_satisfy_submit(): void
    {
        $this->signed(4);
        $before = $this->snapshot();
        $this->postJson($this->url.'/kian-submissions/7', $this->payload() + ['action' => 'submit'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_stage_four_unresolved_is_not_reclassified_and_final_amount_and_document_are_required(): void
    {
        $this->case->update(['spt_position_type' => null]);
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.kian_status_by_stage.4.needsKian', null)->assertJsonPath('data.kian_status_by_stage.4.draft_actions.create', false);
        $this->postJson($this->url.'/kian-submissions/4', $this->payload())->assertUnprocessable();
        $payload = $this->payload(); unset($payload['loss_amount']);
        $this->postJson($this->url.'/kian-submissions/7', $payload + ['action' => 'submit'])->assertUnprocessable();
        $this->postJson($this->url.'/kian-submissions/7', $this->payload() + ['action' => 'submit'])->assertUnprocessable();
        $this->assertDatabaseCount('kian_submissions', 0);
    }
}
