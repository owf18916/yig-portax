<?php

namespace Tests\Feature;

use App\Jobs\SendKianReminderJob;
use App\Models\NotificationAttempt;
use App\Models\{CaseStatus, Currency, Entity, FiscalYear, Role, SkpRecord, SphpRecord, TaxCase, User, WorkflowHistory};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StageSpecificEndpointAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private TaxCase $case;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $entity = Entity::create(['code' => 'AFF', 'name' => 'Affiliate', 'entity_type' => 'AFFILIATE', 'tax_id' => 'AFF-TAX']);
        $role = Role::create(['code' => 'user', 'name' => 'User']);
        $this->user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        $status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        $this->case = TaxCase::create([
            'user_id' => $this->user->id, 'entity_id' => $entity->id,
            'fiscal_year_id' => $year->id, 'currency_id' => $currency->id,
            'case_status_id' => $status->id, 'case_number' => 'ALIGN-01',
            'case_type' => 'CIT', 'reported_amount' => 100, 'disputed_amount' => 100,
            'current_stage' => 4,
        ]);
        $this->actingAs($this->user);
    }

    public function test_sphp_current_fields_persist_and_create_one_valid_draft_history(): void
    {
        $this->case->update(['current_stage' => 2]);
        $payload = [
            'sphp_number' => 'SPHP-01', 'sphp_issue_date' => '2026-09-01',
            'sphp_receipt_date' => '2026-09-02', 'royalty_finding' => 10.25,
            'service_finding' => 20, 'other_finding' => 30,
            'other_finding_notes' => 'Current findings', 'next_action' => 'Review',
            'next_action_due_date' => '2026-10-01', 'status_comment' => 'Pending',
        ];
        $response = $this->postJson($this->url('sphp-records'), $payload)
            ->assertCreated()->assertJsonPath('success', true)
            ->assertJsonPath('data.other_finding_notes', 'Current findings')
            ->assertJsonPath('data.royalty_finding', '10.25');
        $record = SphpRecord::findOrFail($response->json('data.id'));
        foreach ($payload as $key => $value) {
            $actual = $record->getAttribute($key);
            $this->assertEquals($value, $actual instanceof \DateTimeInterface ? $actual->format('Y-m-d') : $actual);
        }
        $this->assertSame($this->case->id, $record->tax_case_id);
        $this->assertSame(3, $this->case->fresh()->current_stage);
        $this->assertSame($this->case->case_status_id, $this->case->fresh()->case_status_id);
        $this->assertHistory(3, 'draft', null);
        foreach (['issued_date', 'summary', 'findings', 'status', 'received_date'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $response->json('data'));
        }
    }

    public function test_sphp_invalid_current_and_legacy_payloads_return_validation_errors_without_writes(): void
    {
        $this->postJson($this->url('sphp-records'), ['sphp_number' => 'SPHP-01', 'sphp_issue_date' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('sphp_issue_date');
        $this->postJson($this->url('sphp-records'), ['sphp_number' => 'SPHP-01', 'issued_date' => '2026-09-01', 'summary' => 'Old', 'findings' => 'Old'])
            ->assertUnprocessable()->assertJsonValidationErrors('sphp_issue_date');
        $this->assertDatabaseCount('sphp_records', 0);
        $this->assertDatabaseCount('workflow_histories', 0);
    }

    public static function skpChoices(): array
    {
        return [
            'date and defaults' => ['2026-10-15', [], 4, null],
            'null and objection' => [null, ['continue_to_next_stage' => true], 5, 5],
            'refund and continue' => ['2026-10-15', ['create_refund' => true, 'refund_amount' => 50, 'continue_to_next_stage' => true, 'user_routing_choice' => 'refund'], 5, 5],
        ];
    }

    #[DataProvider('skpChoices')]
    public function test_skp_valid_requests_persist_reload_and_create_one_history(?string $dueDate, array $choices, int $stage, ?int $next): void
    {
        $payload = array_replace([
            'skp_number' => 'SKP-01', 'issue_date' => '2026-09-01', 'receipt_date' => '2026-09-02',
            'skp_due_date' => $dueDate, 'skp_type' => 'LB', 'skp_amount' => 40,
            'royalty_correction' => 10, 'service_correction' => 20, 'other_correction' => 5,
            'correction_notes' => 'Current corrections', 'user_routing_choice' => 'objection',
        ], $choices);
        $response = $this->postJson($this->url('skp-records'), $payload)->assertCreated()
            ->assertJsonPath('data.skp_amount', '40.00')->assertJsonPath('data.correction_notes', 'Current corrections');
        $record = SkpRecord::findOrFail($response->json('data.id'));
        foreach ($payload as $key => $value) {
            $actual = $record->getAttribute($key);
            $this->assertEquals($value, $actual instanceof \DateTimeInterface ? $actual->format('Y-m-d') : $actual);
        }
        $this->getJson($this->url('skp-records'))->assertOk()->assertJsonPath('data.id', $record->id);
        $this->assertSame($this->case->id, $record->tax_case_id);
        $this->assertSame($stage, $this->case->fresh()->current_stage);
        $this->assertHistory(4, 'submitted', $next);
        $decision = json_decode(WorkflowHistory::sole()->decision_value, true);
        $this->assertSame($choices['create_refund'] ?? false, $decision['create_refund']);
        $this->assertEquals($choices['refund_amount'] ?? null, $decision['refund_amount']);
        $this->assertDatabaseCount('refund_processes', ($choices['create_refund'] ?? false) ? 1 : 0);
        if ($choices['create_refund'] ?? false) {
            $this->assertDatabaseHas('refund_processes', ['tax_case_id' => $this->case->id, 'stage_id' => 4]);
        }
        $this->assertDatabaseCount('notification_attempts', 1);
        $attempt = NotificationAttempt::sole();
        $this->assertSame($this->case->id, $attempt->notificationLog->tax_case_id);
        $this->assertSame(4, $attempt->notificationLog->stage_id);
        Queue::assertPushed(SendKianReminderJob::class, fn ($job) => $job->notificationAttemptId === $attempt->id);
        Queue::assertPushed(SendKianReminderJob::class, 1);
        foreach (['submitted_by', 'submitted_at', 'status', 'next_stage_id'] as $legacy) {
            $this->assertArrayNotHasKey($legacy, $record->getAttributes());
        }
        // Preserve the existing relation key; there is no submission-user column.
        $response->assertJsonPath('data.submitted_by', null);
    }

    public function test_skp_invalid_date_and_wrong_stage_do_not_write(): void
    {
        $payload = ['skp_number' => 'SKP-01', 'issue_date' => '2026-09-01', 'skp_due_date' => 'invalid', 'skp_type' => 'NIHIL', 'skp_amount' => 0, 'user_routing_choice' => 'objection'];
        $this->postJson($this->url('skp-records'), $payload)->assertUnprocessable()->assertJsonValidationErrors('skp_due_date');
        $this->case->update(['current_stage' => 3]);
        $payload['skp_due_date'] = null;
        $this->postJson($this->url('skp-records'), $payload)->assertUnprocessable();
        $this->assertDatabaseCount('skp_records', 0);
        $this->assertDatabaseCount('workflow_histories', 0);
    }

    public function test_existing_historical_records_are_preserved_and_duplicate_creates_return_422(): void
    {
        $sphp = SphpRecord::create(['tax_case_id' => $this->case->id, 'other_finding_notes' => 'Historical']);
        $skp = SkpRecord::create(['tax_case_id' => $this->case->id, 'skp_due_date' => null]);
        $this->postJson($this->url('sphp-records'), ['sphp_number' => 'NEW', 'sphp_issue_date' => '2026-09-01'])->assertUnprocessable();
        $this->postJson($this->url('skp-records'), ['skp_number' => 'NEW', 'issue_date' => '2026-09-01', 'skp_type' => 'NIHIL', 'skp_amount' => 0, 'user_routing_choice' => 'objection'])->assertUnprocessable();
        $this->assertSame('Historical', $sphp->fresh()->other_finding_notes);
        $this->assertNull($skp->fresh()->skp_due_date);
        $sphp->delete();
        $skp->delete();
        $this->postJson($this->url('sphp-records'), ['sphp_number' => 'NEW', 'sphp_issue_date' => '2026-09-01'])->assertUnprocessable();
        $this->postJson($this->url('skp-records'), ['skp_number' => 'NEW', 'issue_date' => '2026-09-01', 'skp_type' => 'NIHIL', 'skp_amount' => 0, 'user_routing_choice' => 'objection'])->assertUnprocessable();
        $this->assertDatabaseCount('workflow_histories', 0);
    }

    public function test_store_routes_still_require_authentication(): void
    {
        auth()->logout();
        $this->postJson($this->url('sphp-records'), [])->assertUnauthorized();
        $this->postJson($this->url('skp-records'), [])->assertUnauthorized();
        $this->assertDatabaseCount('workflow_histories', 0);
    }

    private function url(string $endpoint): string
    {
        return "/api/tax-cases/{$this->case->id}/{$endpoint}";
    }

    private function assertHistory(int $stage, string $status, ?int $next): void
    {
        $this->assertDatabaseCount('workflow_histories', 1);
        $history = WorkflowHistory::sole();
        $this->assertSame($this->case->id, $history->tax_case_id);
        $this->assertSame($stage, $history->stage_id);
        $this->assertSame($this->user->id, $history->user_id);
        $this->assertSame('submitted', $history->action);
        $this->assertSame($status, $history->status);
        $this->assertSame($next, $history->stage_to);
    }
}
