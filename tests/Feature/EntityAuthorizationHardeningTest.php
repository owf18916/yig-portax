<?php

namespace Tests\Feature;

use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\KianSubmission;
use App\Models\Period;
use App\Models\RefundProcess;
use App\Models\Revision;
use App\Models\Role;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EntityAuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $affiliateAUser;

    private User $affiliateBUser;

    private User $holdingUser;

    private User $affiliateAdmin;

    private TaxCase $caseA;

    private TaxCase $caseASecond;

    private TaxCase $caseB;

    protected function setUp(): void
    {
        parent::setUp();

        $affiliateA = Entity::create([
            'code' => 'AFF-A', 'name' => 'Affiliate A', 'entity_type' => 'AFFILIATE', 'tax_id' => 'TAX-A',
        ]);
        $affiliateB = Entity::create([
            'code' => 'AFF-B', 'name' => 'Affiliate B', 'entity_type' => 'AFFILIATE', 'tax_id' => 'TAX-B',
        ]);
        $holding = Entity::create([
            'code' => 'HOLD', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'TAX-H',
        ]);
        $staff = Role::create(['code' => 'STAFF', 'name' => 'STAFF']);
        $admin = Role::create(['code' => 'ADMIN', 'name' => 'ADMIN']);

        $this->affiliateAUser = User::factory()->create(['entity_id' => $affiliateA->id, 'role_id' => $staff->id]);
        $this->affiliateBUser = User::factory()->create(['entity_id' => $affiliateB->id, 'role_id' => $staff->id]);
        $this->holdingUser = User::factory()->create(['entity_id' => $holding->id, 'role_id' => $staff->id]);
        $this->affiliateAdmin = User::factory()->create(['entity_id' => $affiliateA->id, 'role_id' => $admin->id]);

        $year = FiscalYear::create([
            'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);
        $period = Period::create([
            'fiscal_year_id' => $year->id, 'period_code' => '2026-03', 'year' => 2026, 'month' => 3,
            'start_date' => '2026-03-01', 'end_date' => '2026-03-31',
        ]);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        $status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);

        $this->caseA = $this->makeCase($affiliateA, $this->affiliateAUser, $year, $period, $currency, $status, 'CASE-A');
        $this->caseASecond = $this->makeCase($affiliateA, $this->affiliateAUser, $year, $period, $currency, $status, 'CASE-A2', 'VAT');
        $this->caseB = $this->makeCase($affiliateB, $this->affiliateBUser, $year, $period, $currency, $status, 'CASE-B');
    }

    public function test_affiliate_cannot_reach_cross_entity_core_or_workflow_endpoints(): void
    {
        $this->actingAs($this->affiliateAUser);
        $base = "/api/tax-cases/{$this->caseB->id}";

        $this->getJson($base)->assertForbidden();
        $this->putJson($base, ['description' => 'unauthorized'])->assertForbidden();
        $this->postJson("{$base}/workflow/1", ['action' => 'draft'])->assertForbidden();
        $this->getJson("{$base}/workflow-history")->assertForbidden();
        $this->postJson("{$base}/complete")->assertForbidden();
        $this->postJson('/api/tax-cases', [
            'entity_id' => $this->caseB->entity_id,
            'case_type' => 'CIT',
            'fiscal_year_id' => $this->caseB->fiscal_year_id,
            'period_id' => $this->caseB->period_id,
            'disputed_amount' => 100,
        ])->assertForbidden();

        $this->assertSame('CASE-B', $this->caseB->fresh()->case_number);
        $this->assertNull($this->caseB->fresh()->description);
        $this->assertDatabaseCount('workflow_histories', 0);
    }

    public function test_authorized_same_entity_and_holding_access_remain_available_without_admin_bypass(): void
    {
        $this->actingAs($this->affiliateAUser)
            ->getJson("/api/tax-cases/{$this->caseA->id}")
            ->assertOk();

        $this->actingAs($this->holdingUser)
            ->getJson("/api/tax-cases/{$this->caseB->id}")
            ->assertOk();

        $this->actingAs($this->affiliateAdmin)
            ->getJson("/api/tax-cases/{$this->caseB->id}")
            ->assertForbidden();
    }

    public function test_documents_inherit_tax_case_entity_scope_for_list_upload_view_download_and_delete(): void
    {
        $ownDocument = $this->makeDocument($this->caseA, 'own.pdf');
        $foreignDocument = $this->makeDocument($this->caseB, 'foreign.pdf');
        $this->actingAs($this->affiliateAUser);

        $this->getJson("/api/tax-cases/{$this->caseB->id}/documents")->assertForbidden();
        $this->getJson("/api/documents?tax_case_id={$this->caseB->id}")->assertForbidden();
        $this->getJson("/api/documents/{$foreignDocument->id}/view")->assertForbidden();
        $this->getJson("/api/documents/{$foreignDocument->id}/download")->assertForbidden();
        $this->deleteJson("/api/documents/{$foreignDocument->id}")->assertForbidden();
        $this->post('/api/documents', [
            'file' => UploadedFile::fake()->create('blocked.pdf', 1, 'application/pdf'),
            'tax_case_id' => $this->caseB->id,
            'documentable_type' => 'App\\Models\\WorkflowHistory',
            'documentable_id' => 1,
            'stage_code' => '1',
            'document_type' => 'supporting_document',
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/api/tax-cases/{$this->caseA->id}/revisions/request", [
            'payload' => json_encode([
                'fields' => ['supporting_docs'],
                'reason' => 'Attempt to substitute a foreign document',
                'proposed_values' => [],
                'proposed_document_changes' => [
                    'files_to_delete' => [$foreignDocument->id],
                    'files_to_add' => [],
                ],
            ]),
        ])->assertNotFound();

        $this->getJson('/api/documents?status=ACTIVE')
            ->assertOk()
            ->assertJsonFragment(['id' => $ownDocument->id])
            ->assertJsonMissing(['id' => $foreignDocument->id]);
        $this->assertDatabaseHas('documents', ['id' => $foreignDocument->id, 'deleted_at' => null]);
    }

    public function test_kian_refund_and_revision_routes_reject_cross_entity_ids(): void
    {
        $kian = KianSubmission::create([
            'tax_case_id' => $this->caseB->id, 'stage_id' => 4, 'kian_number' => 'KIAN-B',
            'submission_date' => '2026-09-01', 'kian_amount' => 100, 'status' => 'draft',
        ]);
        $refund = RefundProcess::create([
            'tax_case_id' => $this->caseB->id, 'stage_id' => 4, 'refund_number' => 'REF-B',
            'refund_amount' => 100, 'refund_method' => 'bank_transfer', 'refund_status' => 'pending',
            'status' => 'draft', 'sequence_number' => 1,
        ]);
        Revision::create([
            'revisable_type' => 'TaxCase', 'revisable_id' => $this->caseB->id,
            'revision_status' => 'requested', 'requested_by' => $this->affiliateBUser->id,
            'requested_at' => now(), 'reason' => 'Cross entity regression test',
        ]);

        $this->actingAs($this->affiliateAUser);
        $this->postJson("/api/tax-cases/{$this->caseB->id}/kian-submissions/{$kian->id}/submit")->assertForbidden();
        $this->getJson("/api/tax-cases/{$this->caseB->id}/refund-processes/{$refund->id}")->assertForbidden();
        $this->getJson("/api/tax-cases/{$this->caseB->id}/revisions")->assertForbidden();
    }

    public function test_nested_resource_ids_cannot_be_substituted_between_accessible_cases(): void
    {
        $kian = KianSubmission::create([
            'tax_case_id' => $this->caseASecond->id, 'stage_id' => 4, 'kian_number' => 'KIAN-A2',
            'submission_date' => '2026-09-01', 'kian_amount' => 100, 'status' => 'draft',
        ]);
        $refund = RefundProcess::create([
            'tax_case_id' => $this->caseASecond->id, 'stage_id' => 4, 'refund_number' => 'REF-A2',
            'refund_amount' => 100, 'refund_method' => 'bank_transfer', 'refund_status' => 'pending',
            'status' => 'draft', 'sequence_number' => 1,
        ]);
        $revision = Revision::create([
            'revisable_type' => 'TaxCase', 'revisable_id' => $this->caseASecond->id,
            'revision_status' => 'requested', 'requested_by' => $this->affiliateAUser->id,
            'requested_at' => now(), 'reason' => 'Nested ownership regression test',
        ]);

        $this->actingAs($this->affiliateAUser);
        $this->postJson("/api/tax-cases/{$this->caseA->id}/kian-submissions/{$kian->id}/submit")->assertNotFound();
        $this->getJson("/api/tax-cases/{$this->caseA->id}/refund-processes/{$refund->id}")->assertNotFound();
        $this->putJson("/api/tax-cases/{$this->caseA->id}/next-action/kian-submissions/{$kian->id}", [
            'next_action' => 'tampered',
        ])->assertNotFound();

        $this->actingAs($this->holdingUser)
            ->patchJson("/api/tax-cases/{$this->caseA->id}/revisions/{$revision->id}/decide", [
                'decision' => 'reject', 'rejection_reason' => 'Should not apply to another case',
            ])->assertNotFound();

        $this->assertSame('draft', $kian->fresh()->status);
        $this->assertSame('requested', $revision->fresh()->revision_status);
    }

    private function makeCase(
        Entity $entity,
        User $user,
        FiscalYear $year,
        Period $period,
        Currency $currency,
        CaseStatus $status,
        string $number,
        string $caseType = 'CIT'
    ): TaxCase {
        return TaxCase::create([
            'user_id' => $user->id, 'entity_id' => $entity->id,
            'fiscal_year_id' => $year->id, 'period_id' => $period->id,
            'currency_id' => $currency->id, 'case_status_id' => $status->id,
            'case_number' => $number, 'case_type' => $caseType,
            'reported_amount' => 1000, 'disputed_amount' => 1000,
            'current_stage' => 12,
        ]);
    }

    private function makeDocument(TaxCase $taxCase, string $filename): Document
    {
        return Document::create([
            'documentable_type' => 'TaxCase', 'documentable_id' => $taxCase->id,
            'tax_case_id' => $taxCase->id, 'document_type' => 'supporting_document',
            'stage_code' => '1', 'original_filename' => $filename,
            'file_path' => "tax_cases/{$taxCase->id}/documents/1/{$filename}",
            'file_mime_type' => 'application/pdf', 'file_size' => 100,
            'uploaded_by' => $taxCase->user_id, 'uploaded_at' => now(), 'status' => 'ACTIVE',
        ]);
    }
}
