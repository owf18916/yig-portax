<?php

namespace Tests\Feature;

use App\Models\{CaseStatus, Currency, Document, Entity, FiscalYear, Period, Role, TaxCase, User, WorkflowHistory};
use App\Services\RevisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SptFilingContractTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private TaxCase $case;
    private Period $period;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $entity = Entity::create(['code'=>'SPT','name'=>'SPT contract','entity_type'=>'AFFILIATE','tax_id'=>'SPT']);
        $role = Role::create(['code'=>'STAFF','name'=>'Staff']);
        $this->user = User::factory()->create(['entity_id'=>$entity->id,'role_id'=>$role->id]);
        $year = FiscalYear::create(['year'=>2026,'start_date'=>'2026-01-01','end_date'=>'2026-12-31']);
        Currency::forceCreate(['id'=>1,'code'=>'IDR','name'=>'Rupiah','symbol'=>'Rp']);
        CaseStatus::forceCreate(['id'=>1,'code'=>'OPEN','name'=>'Open']);
        CaseStatus::forceCreate(['id'=>2,'code'=>'SUBMITTED','name'=>'Submitted']);
        $this->period = Period::create(['fiscal_year_id'=>$year->id,'period_code'=>'2026-03','year'=>2026,'month'=>3,'start_date'=>'2026-03-01','end_date'=>'2026-03-31']);
        $this->case = TaxCase::create(['entity_id'=>$entity->id,'user_id'=>$this->user->id,'fiscal_year_id'=>$year->id,'case_number'=>'SPT-TEST','case_type'=>'CIT','case_status_id'=>1,'currency_id'=>1,'spt_position_type'=>'LB','reported_amount'=>1000,'disputed_amount'=>1000,'current_stage'=>1]);
        $this->actingAs($this->user);
    }

    public static function methods(): array
    {
        return [
            ['CIT','LB','Restitusi',true], ['CIT','LB','Kompensasi',true],
            ['VAT','LB','Restitusi',true], ['VAT','LB','Kompensasi',true],
            ['VAT','LB','Pengembalian Pendahuluan',true],
            ['CIT','LB','Pengembalian Pendahuluan',false],
            ['VAT','KB','Pengembalian Pendahuluan',false],
            ['VAT','NIHIL','Pengembalian Pendahuluan',false],
            ['CIT','KB',null,true], ['VAT','NIHIL',null,true],
            ['CIT','KB','Restitusi',false], ['VAT','NIHIL','Kompensasi',false],
        ];
    }

    #[DataProvider('methods')]
    public function test_create_and_stage_one_share_the_method_contract(string $type, string $position, ?string $method, bool $valid): void
    {
        $values = ['case_type'=>$type,'spt_position_type'=>$position,'spt_type'=>$method,'disputed_amount'=>$position === 'NIHIL' ? 0 : 1000];
        $create = $values + ['entity_id'=>$this->case->entity_id,'fiscal_year_id'=>$this->case->fiscal_year_id,'period_id'=>$this->period->id];
        $response = $this->postJson('/api/tax-cases', $create);
        if ($valid) {
            $response->assertCreated()->assertJsonPath('data.spt_type', $method);
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('spt_type');
        }
        $this->case->update(['case_type'=>$type]);
        foreach (['draft', 'submit'] as $action) {
            if ($action === 'submit') $this->document();
            $response = $this->postJson($this->workflowUrl(), $values + ['action'=>$action]);
            if ($valid) {
                $response->assertOk();
                $this->assertSame($method, $this->case->fresh()->spt_type);
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors('spt_type');
                $this->assertNull($this->case->fresh()->spt_type);
            }
        }
        $this->assertDatabaseCount('refund_processes', 0);
    }

    public static function historical(): array
    {
        return [['SPT'], ['Kurang Bayar'], [null], ['Pengembalian Pendahuluan'], ['Unknown historical method']];
    }

    #[DataProvider('historical')]
    public function test_omission_and_unchanged_values_preserve_historical_records(?string $method): void
    {
        // Deliberately contradictory historical CIT preliminary remains unchanged.
        $this->case->update(['spt_type'=>$method]);
        $this->postJson($this->workflowUrl(), ['action'=>'draft','disputed_amount'=>900])->assertOk();
        $this->assertSame($method, $this->case->fresh()->spt_type);
        $this->document();
        $this->postJson($this->workflowUrl(), ['action'=>'submit','spt_type'=>$method])->assertOk();
        $this->assertSame($method, $this->case->fresh()->spt_type);
        $service = app(RevisionService::class);
        $revision = $service->requestRevision($this->case->fresh(), $this->user, ['description'=>'Reviewed without reclassifying'], [], 'Preserve historical method evidence.', ['description'], 1);
        $service->decideRevision($revision, $this->case->fresh(), $this->user, 'approve');
        $this->assertSame($method, $this->case->fresh()->spt_type);
        $this->assertNotContains('spt_type', $revision->fresh()->audit_data['changed_fields']);
    }

    public function test_explicit_clear_is_distinct_from_omission_and_position_change_requires_consistency(): void
    {
        $this->case->update(['spt_type'=>'Restitusi']);
        $this->postJson($this->workflowUrl(), ['action'=>'draft','spt_position_type'=>'KB'])->assertUnprocessable();
        $this->assertSame('LB', $this->case->fresh()->spt_position_type);
        $this->postJson($this->workflowUrl(), ['action'=>'draft','spt_position_type'=>'KB','spt_type'=>null])->assertOk();
        $this->assertNull($this->case->fresh()->spt_type);
        $this->assertSame('KB', $this->case->fresh()->spt_position_type);
    }

    public function test_approved_revision_validates_merged_values_and_retains_audit_evidence(): void
    {
        $this->case->update(['case_type'=>'VAT','spt_type'=>'SPT']);
        $this->history();
        $service = app(RevisionService::class);
        $revision = $service->requestRevision($this->case->fresh(), $this->user, ['spt_type'=>'Pengembalian Pendahuluan'], [], 'Use the VAT preliminary method.', ['spt_type'], 1);
        $service->decideRevision($revision, $this->case->fresh(), $this->user, 'approve');
        $this->assertSame('SPT', $revision->fresh()->original_data['spt_type']);
        $this->assertSame('SPT', $revision->fresh()->audit_data['before']['spt_type']);
        $this->assertSame('Pengembalian Pendahuluan', $this->case->fresh()->spt_type);
        $this->assertDatabaseCount('refund_processes', 0);
    }

    public function test_preexisting_invalid_revision_cannot_bypass_approval_validation(): void
    {
        $this->history();
        $service = app(RevisionService::class);
        $revision = $service->requestRevision($this->case->fresh(), $this->user, ['spt_type'=>'Restitusi'], [], 'A previously accepted request.', ['spt_type'], 1);
        $revision->update(['proposed_values'=>['spt_type'=>'Pengembalian Pendahuluan']]);
        try {
            $service->decideRevision($revision, $this->case->fresh(), $this->user, 'approve');
            $this->fail('Contradictory revision was accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('spt_type', $e->errors());
        }
        $this->assertNull($this->case->fresh()->spt_type);
        $this->assertSame('requested', $revision->fresh()->revision_status);
        $this->assertNull($revision->fresh()->audit_data);
    }

    public function test_revision_omitting_method_cannot_change_preliminary_position_to_kb(): void
    {
        $this->case->update(['case_type'=>'VAT','spt_type'=>'Pengembalian Pendahuluan']);
        $this->history();
        $this->expectException(ValidationException::class);
        app(RevisionService::class)->requestRevision($this->case->fresh(), $this->user, ['spt_position_type'=>'KB'], [], 'Must validate the merged method.', ['spt_position_type'], 1);
    }

    public function test_new_unknown_and_overlong_methods_are_rejected(): void
    {
        foreach (['unknown', str_repeat('a', 51)] as $method) {
            $this->postJson($this->workflowUrl(), ['action'=>'draft','spt_type'=>$method])->assertUnprocessable()->assertJsonValidationErrors('spt_type');
        }
    }

    #[DataProvider('methods')]
    public function test_revision_uses_the_same_merged_contract(string $type, string $position, ?string $method, bool $valid): void
    {
        $this->case->update(['case_type'=>$type,'spt_position_type'=>$position,'disputed_amount'=>$position === 'NIHIL' ? 0 : 1000]);
        $this->history();
        $service = app(RevisionService::class);
        if (!$valid) $this->expectException(ValidationException::class);
        $revision = $service->requestRevision($this->case->fresh(), $this->user, ['spt_type'=>$method], [], 'Use the selected filing method.', ['spt_type'], 1);
        $service->decideRevision($revision, $this->case->fresh(), $this->user, 'approve');
        $this->assertSame($method, $this->case->fresh()->spt_type);
        $this->assertDatabaseCount('refund_processes', 0);
    }

    public static function genericClients(): array
    {
        return [[[]], [['spt_type'=>null]], [['spt_type'=>'SPT']], [['spt_type'=>'Kurang Bayar']]];
    }

    #[DataProvider('genericClients')]
    public function test_generic_create_clients_retain_nullable_and_legacy_compatibility(array $method): void
    {
        $this->postJson('/api/tax-cases', $method + ['entity_id'=>$this->case->entity_id,
            'fiscal_year_id'=>$this->case->fiscal_year_id,'period_id'=>$this->period->id,
            'case_type'=>'CIT','spt_position_type'=>'KB','disputed_amount'=>1000])
            ->assertCreated()->assertJsonPath('data.spt_type', $method['spt_type'] ?? null);
    }

    public function test_contract_validation_adds_no_queries(): void
    {
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        app(\App\Services\SptFilingContract::class)->validate(['spt_type'=>'Restitusi'], $this->case);
        $this->assertSame([], \Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
    }

    private function workflowUrl(): string { return "/api/tax-cases/{$this->case->id}/workflow/1"; }
    private function history(): void
    {
        WorkflowHistory::create(['tax_case_id'=>$this->case->id,'stage_id'=>1,'stage_from'=>1,'stage_to'=>2,'action'=>'submitted','status'=>'submitted','user_id'=>$this->user->id]);
    }
    private function document(): void
    {
        Document::create(['tax_case_id'=>$this->case->id,'documentable_type'=>TaxCase::class,'documentable_id'=>$this->case->id,'stage_code'=>'1','document_type'=>'supporting','original_filename'=>'spt.pdf','file_path'=>'testing/spt.pdf','file_size'=>1,'file_mime_type'=>'application/pdf','uploaded_at'=>now(),'uploaded_by'=>$this->user->id,'status'=>'ACTIVE']);
    }
}
