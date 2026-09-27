<?php

namespace Tests\Feature;

use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\Role;
use App\Models\SkpRecord;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkflowFieldImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private TaxCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        [$entity] = $this->entities();
        $user = $this->user($entity);
        $this->case = $this->taxCase($entity, $user, $this->period('2026-03', 2026, 3), 'CIT');
        CaseStatus::create(['code' => 'SUBMITTED', 'name' => 'Submitted']);
        $this->actingAs($user);
        Storage::fake(config('filesystems.default'));
    }

    public function test_sp2_specific_endpoint_and_workflow_save_and_reload_free_text(): void
    {
        $payload = ['auditor_position' => 'Senior Auditor', 'auditor_email' => 'Contact team / alice; bob'];
        $this->postJson("/api/tax-cases/{$this->case->id}/sp2-records", $payload)
            ->assertCreated()->assertJsonPath('data.auditor_position', 'Senior Auditor');
        $this->getJson("/api/tax-cases/{$this->case->id}/sp2-records")
            ->assertOk()->assertJsonPath('data.auditor_email', $payload['auditor_email']);
        $payload['auditor_position'] = 'Team Lead';
        $payload['action'] = 'draft';
        $this->postJson("/api/tax-cases/{$this->case->id}/workflow/2", $payload)->assertOk();
        $this->getJson("/api/tax-cases/{$this->case->id}")
            ->assertOk()->assertJsonPath('data.sp2_record.auditor_position', 'Team Lead');
        $this->postJson("/api/tax-cases/{$this->case->id}/workflow/2", ['action' => 'draft', 'auditor_email' => str_repeat('a', 256)])
            ->assertUnprocessable()->assertJsonValidationErrors('auditor_email');
    }

    public function test_skp_due_date_persists_reloads_clears_and_validates(): void
    {
        SkpRecord::create(['tax_case_id' => $this->case->id]);
        $this->getJson("/api/tax-cases/{$this->case->id}/skp-records")
            ->assertOk()->assertJsonPath('data.skp_due_date', null);
        $url = "/api/tax-cases/{$this->case->id}/workflow/4";
        $this->postJson($url, ['action' => 'draft', 'skp_due_date' => '2026-10-15'])->assertOk();
        $this->assertSame('2026-10-15', $this->case->fresh()->skpRecord->skp_due_date->toDateString());
        $date = $this->getJson("/api/tax-cases/{$this->case->id}/skp-records")->assertOk()->json('data.skp_due_date');
        $this->assertStringStartsWith('2026-10-15', $date);
        $this->postJson($url, ['action' => 'draft', 'skp_due_date' => null])->assertOk();
        $this->assertNull($this->case->fresh()->skpRecord->skp_due_date);
        $this->postJson($url, ['action' => 'draft', 'skp_due_date' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('skp_due_date');
    }

    public function test_skp_specific_endpoint_validates_due_date(): void
    {
        $this->case->update(['current_stage' => 4]);
        $this->postJson("/api/tax-cases/{$this->case->id}/skp-records", [
            'skp_number' => 'SKP-01', 'issue_date' => '2026-09-01', 'skp_due_date' => 'invalid',
            'skp_type' => 'NIHIL', 'skp_amount' => 100, 'user_routing_choice' => 'objection',
        ])->assertUnprocessable()->assertJsonValidationErrors('skp_due_date');
    }

    public function test_all_office_extensions_attach_to_correct_case_stage_and_purpose(): void
    {
        $mimes = [
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xlsb' => 'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
            'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
        ];
        foreach ($mimes as $extension => $mime) {
            $response = $this->postJson('/api/documents', $this->attachment("findings.$extension", $mime))->assertCreated();
            $document = Document::findOrFail($response->json('data.id'));
            $this->assertSame($this->case->id, $document->tax_case_id);
            $this->assertSame('3', $document->stage_code);
            $this->assertSame(Document::SPHP_OTHER_FINDINGS, $document->document_type);
            $this->assertSame('App\\Models\\WorkflowHistory', $document->documentable_type);
            Storage::disk(config('filesystems.default'))->assertExists($document->file_path);
        }
        $this->getJson("/api/documents?tax_case_id={$this->case->id}&stage_code=3&status=DRAFT")
            ->assertOk()->assertJsonCount(6, 'data');
    }

    public function test_supplementary_rejects_wrong_extension_mime_stage_and_size(): void
    {
        $this->postJson('/api/documents', $this->attachment('findings.pdf', 'application/pdf'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/documents', $this->attachment('findings.docx', 'text/plain'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson('/api/documents', array_replace($this->attachment('findings.doc', 'application/msword'), ['stage_code' => '2']))
            ->assertUnprocessable()->assertJsonValidationErrors('stage_code');
        $payload = $this->attachment('findings.doc', 'application/msword');
        $payload['file'] = UploadedFile::fake()->create('findings.doc', 10241, 'application/msword');
        $this->postJson('/api/documents', $payload)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, Document::count());
    }

    public function test_existing_supporting_upload_remains_pdf_only(): void
    {
        $payload = $this->attachment('support.pdf', 'application/pdf');
        $payload['document_type'] = 'supporting_document';
        $this->postJson('/api/documents', $payload)->assertCreated();
        $payload['file'] = UploadedFile::fake()->create('support.doc', 1, 'application/msword');
        $this->postJson('/api/documents', $payload)->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_optional_attachment_cannot_replace_supporting_document_and_notes_persist(): void
    {
        $this->postJson('/api/documents', $this->attachment('findings.doc', 'application/msword'))->assertCreated();
        $url = "/api/tax-cases/{$this->case->id}/workflow/3";
        $this->postJson($url, ['action' => 'submit', 'other_finding_notes' => 'As attched'])
            ->assertUnprocessable()->assertJsonValidationErrors('supporting_docs');
        $this->assertNull($this->case->fresh()->sphpRecord);
        $this->postJson($url, ['action' => 'draft', 'other_finding_notes' => 'Manually edited note'])->assertOk();
        $this->assertSame('Manually edited note', $this->case->fresh()->sphpRecord->other_finding_notes);
        $payload = $this->attachment('support.pdf', 'application/pdf');
        $payload['document_type'] = 'supporting_document';
        $this->postJson('/api/documents', $payload)->assertCreated();
        $this->postJson($url, ['action' => 'submit', 'other_finding_notes' => 'As attched'])->assertOk();
        $this->getJson("/api/tax-cases/{$this->case->id}")
            ->assertOk()->assertJsonPath('data.sphp_record.other_finding_notes', 'As attched');
    }

    private function attachment(string $name, string $mime): array
    {
        return [
            'file' => UploadedFile::fake()->create($name, 1, $mime),
            'tax_case_id' => $this->case->id,
            'documentable_type' => 'App\\Models\\WorkflowHistory',
            'documentable_id' => 3, 'stage_code' => '3',
            'document_type' => Document::SPHP_OTHER_FINDINGS,
        ];
    }

    private function entities(): array
    {
        $affiliate = Entity::create(['code' => 'AFF', 'name' => 'Affiliate', 'entity_type' => 'AFFILIATE', 'tax_id' => 'AFF-TAX']);
        $other = Entity::create(['code' => 'OTH', 'name' => 'Other Affiliate', 'entity_type' => 'AFFILIATE', 'tax_id' => 'OTH-TAX']);
        $holding = Entity::create(['code' => 'HLD', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'HLD-TAX']);

        return [$affiliate, $other, $holding];
    }

    private function user(Entity $entity): User
    {
        $role = Role::firstOrCreate(['code' => 'user'], ['name' => 'User']);

        return User::factory()->create([
            'entity_id' => $entity->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function period(string $code, int $year, int $month): Period
    {
        $fiscalYear = FiscalYear::firstOrCreate(
            ['year' => $month <= 3 ? $year : $year + 1],
            ['start_date' => "{$year}-01-01", 'end_date' => "{$year}-12-31"]
        );

        return Period::firstOrCreate(
            ['period_code' => $code, 'fiscal_year_id' => $fiscalYear->id],
            ['year' => $year, 'month' => $month, 'start_date' => "{$code}-01", 'end_date' => "{$code}-28", 'is_closed' => true]
        );
    }

    private function taxCase(Entity $entity, User $user, Period $period, string $type): TaxCase
    {
        Currency::firstOrCreate(['code' => 'IDR'], ['name' => 'Rupiah', 'symbol' => 'Rp']);
        CaseStatus::firstOrCreate(['code' => 'OPEN'], ['name' => 'Open']);

        return TaxCase::create([
            'user_id' => $user->id,
            'entity_id' => $entity->id,
            'fiscal_year_id' => $period->fiscal_year_id,
            'period_id' => $period->id,
            'currency_id' => Currency::first()->id,
            'case_status_id' => CaseStatus::first()->id,
            'case_number' => "{$entity->code}{$period->period_code}{$type}",
            'case_type' => $type,
            'reported_amount' => 100,
            'disputed_amount' => 100,
        ]);
    }
}
