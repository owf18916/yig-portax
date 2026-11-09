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

class PostSubmitFollowUpAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private TaxCase $case;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        \Illuminate\Support\Facades\Mail::fake();

        $entity = Entity::create([
            'code' => 'GUARD', 'name' => 'Guard Entity', 'entity_type' => 'AFFILIATE', 'tax_id' => 'GUARD-TAX',
        ]);
        $role = Role::create(['code' => 'STAFF', 'name' => 'Staff']);
        $this->user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        // Match the submit route's fresh-seeded IDs; PostgreSQL sequences survive rollback.
        $open = CaseStatus::forceCreate(['id' => 1, 'code' => 'OPEN', 'name' => 'Open']);
        CaseStatus::forceCreate(['id' => 2, 'code' => 'SUBMITTED', 'name' => 'Submitted']);
        $this->case = TaxCase::create([
            'user_id' => $this->user->id,
            'entity_id' => $entity->id,
            'fiscal_year_id' => $year->id,
            'currency_id' => $currency->id,
            'case_status_id' => $open->id,
            'case_number' => 'GUARD-2026-01',
            'case_type' => 'CIT',
            'spt_position_type' => 'LB',
            'reported_amount' => 100,
            'disputed_amount' => 100,
            'current_stage' => 1,
        ]);
        $this->actingAs($this->user);
    }

    public static function operationalStages(): array
    {
        return [[1, 'tax-cases'], [2, 'sp2-records'], [3, 'sphp-records'], [4, 'skp-records'],
            [5, 'objection-submissions'], [6, 'spuh-records'], [7, 'objection-decisions'],
            [8, 'appeal-submissions'], [9, 'appeal-explanation-requests'], [10, 'appeal-decisions'], [11, 'supreme-court-submissions']];
    }

    private function history(int $stage): void
    {
        WorkflowHistory::create(['tax_case_id' => $this->case->id, 'stage_id' => $stage,
            'stage_from' => $stage, 'stage_to' => $stage + 1, 'action' => 'submitted', 'status' => 'submitted',
            'user_id' => $this->user->id]);
    }

    private function record(int $stage): \Illuminate\Database\Eloquent\Model
    {
        return match ($stage) {
            1 => $this->case,
            2 => \App\Models\Sp2Record::create(['tax_case_id' => $this->case->id, 'sp2_number' => 'NA1-SP2']),
            3 => \App\Models\SphpRecord::create(['tax_case_id' => $this->case->id]),
            4 => SkpRecord::create(['tax_case_id' => $this->case->id, 'skp_number' => 'NA1-SKP', 'skp_type' => 'LB', 'skp_amount' => 100]),
            5 => \App\Models\ObjectionSubmission::create(['tax_case_id' => $this->case->id, 'objection_number' => 'NA1-OBJ', 'submission_date' => '2026-11-01', 'objection_amount' => 100]),
            6 => \App\Models\SpuhRecord::create(['tax_case_id' => $this->case->id]),
            8 => \App\Models\AppealSubmission::create(['tax_case_id' => $this->case->id, 'appeal_number' => 'NA1-APL', 'submission_date' => '2026-11-01', 'appeal_amount' => 100]),
            9 => \App\Models\AppealExplanationRequest::create(['tax_case_id' => $this->case->id]),
            10 => \App\Models\AppealDecision::create(['tax_case_id' => $this->case->id, 'status' => 'submitted']),
            11 => \App\Models\SupremeCourtSubmission::create(['tax_case_id' => $this->case->id, 'submission_number' => 'NA1-SC', 'submission_date' => '2026-11-01', 'submission_amount' => 100]),
            7 => \App\Models\ObjectionDecision::create(['tax_case_id' => $this->case->id,
                'decision_number' => 'NA1-DEC', 'decision_date' => '2026-11-01', 'decision_type' => 'granted',
                'decision_amount' => 100, 'status' => 'submitted']),
        };
    }

    #[DataProvider('operationalStages')]
    public function test_completed_stage_fields_persist_independently_without_business_or_lifecycle_mutation(int $stage, string $type): void
    {
        $record = $this->record($stage);
        $this->history($stage);
        // A later stage already started; current_stage is deliberately not the access authority.
        $this->history($stage + 1);
        $before = $this->case->fresh()->getAttributes();
        $recordBefore = $record->fresh()->getAttributes();
        $history = WorkflowHistory::all()->toArray();
        $url = "/api/tax-cases/{$this->case->id}/next-action/{$type}/{$record->id}";
        $payload = ['next_action' => 'Call tax office', 'next_action_due_date' => '2026-12-01', 'status_comment' => 'Waiting'];
        $this->putJson($url, $payload)->assertOk();
        $saved = $record->fresh();
        $this->assertSame('Call tax office', $saved->next_action);
        $this->assertSame('2026-12-01', $saved->next_action_due_date->toDateString());
        $this->assertSame('Waiting', $saved->status_comment);
        $this->putJson($url, ['status_comment' => 'Updated'])->assertOk();
        $this->assertSame('Call tax office', $record->fresh()->next_action);
        $this->assertSame('2026-12-01', $record->fresh()->next_action_due_date->toDateString());
        foreach (['decision_type', 'decision_amount', 'status', 'current_stage', 'entity_id'] as $key) {
            $this->putJson($url, ['next_action' => 'Tampered', $key => 999])->assertUnprocessable();
            $this->assertSame('Call tax office', $record->fresh()->next_action);
        }
        $this->putJson($url, ['next_action_due_date' => 'invalid'])->assertUnprocessable();
        $this->putJson($url, ['next_action' => str_repeat('x', 1001)])->assertUnprocessable();
        $this->putJson($url, ['next_action' => null, 'next_action_due_date' => null, 'status_comment' => ''])->assertOk();
        $this->assertNull($record->fresh()->next_action);
        $this->assertNull($record->fresh()->next_action_due_date);
        $this->assertNull($record->fresh()->status_comment);
        foreach ($before as $key => $value) {
            if (!in_array($key, ['next_action', 'next_action_due_date', 'status_comment', 'updated_at'])) {
                $this->assertSame($value, $this->case->fresh()->getAttributes()[$key], $key);
            }
        }
        foreach ($recordBefore as $key => $value) {
            if (!in_array($key, ['next_action', 'next_action_due_date', 'status_comment', 'updated_at'])) {
                $this->assertSame($value, $record->fresh()->getAttributes()[$key], $key);
            }
        }
        $this->assertSame($history, WorkflowHistory::all()->toArray());
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('kian_submissions', 0);
        $this->assertDatabaseCount('revisions', 0);
        Queue::assertNothingPushed();
        \Illuminate\Support\Facades\Mail::assertNothingSent();
        $this->getJson("/api/tax-cases/{$this->case->id}")->assertOk()->assertJsonPath('data.can_update_follow_up', true);
    }

    public function test_available_draft_works_but_locked_and_closed_cases_do_not(): void
    {
        $record = $this->record(2);
        $url = "/api/tax-cases/{$this->case->id}/next-action/sp2-records/{$record->id}";
        $this->putJson($url, ['next_action' => 'Locked'])->assertConflict();
        $this->history(1);
        $this->putJson($url, ['next_action' => 'Draft follow-up'])->assertOk();
        $this->case->update(['is_completed' => true]);
        $this->putJson($url, ['next_action' => 'Closed'])->assertConflict();
        $this->case->update(['is_completed' => false, 'case_status_id' => CaseStatus::create(['code' => 'CLOSED', 'name' => 'Closed'])->id]);
        $this->putJson($url, ['next_action' => 'Closed'])->assertConflict();
        $this->assertSame('Draft follow-up', $record->fresh()->next_action);
        $this->getJson("/api/tax-cases/{$this->case->id}")->assertOk()->assertJsonPath('data.can_update_follow_up', false);
    }

    public function test_stage_notes_are_independent_and_existing_case_policy_is_preserved(): void
    {
        $earlier = $this->record(2);
        $later = $this->record(7);
        $later->update(['next_action' => 'Independent decision follow-up', 'status_comment' => 'Retain']);
        $this->history(2);
        $this->history(7);
        // The existing operational endpoint uses case update/view, not submit_cases.
        $this->assertFalse($this->user->hasPermission('submit_cases'));
        $url = "/api/tax-cases/{$this->case->id}/next-action/sp2-records/{$earlier->id}";
        $this->putJson($url, ['next_action' => 'Earlier follow-up'])->assertOk();
        $this->putJson($url, ['next_action' => 'Earlier follow-up'])->assertOk();
        $this->assertSame('Independent decision follow-up', $later->fresh()->next_action);
        $this->assertSame('Retain', $later->fresh()->status_comment);
        $this->assertDatabaseCount('workflow_histories', 2);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('kian_submissions', 0);
        Queue::assertNothingPushed();
    }

    public function test_legacy_stage_twelve_remains_read_only_and_earlier_stage_access_is_preserved(): void
    {
        $decision = \App\Models\SupremeCourtDecision::create(['tax_case_id' => $this->case->id, 'status' => 'submitted']);
        $this->history(12);
        $this->putJson("/api/tax-cases/{$this->case->id}/next-action/supreme-court-decisions/{$decision->id}",
            ['next_action_due_date' => '2026-12-01', 'status_comment' => 'Waiting'])->assertConflict();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('supreme_court_decisions', 'status_comment'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('supreme_court_decisions', 'next_action_due_date'));
        // Earlier completed stages remain operable even when Stage 12 makes the workflow terminal.
        $this->history(1);
        $this->putJson("/api/tax-cases/{$this->case->id}/next-action/tax-cases/{$this->case->id}", ['next_action' => 'Follow up'])->assertOk();
    }

    public function test_entity_denial_and_mismatched_child_preserve_records(): void
    {
        $record = $this->record(2);
        $this->history(2);
        $other = $this->case->replicate();
        $other->case_number = 'NA1-OTHER';
        $other->case_type = 'VAT';
        $other->save();
        $this->putJson("/api/tax-cases/{$other->id}/next-action/sp2-records/{$record->id}", ['next_action' => 'Wrong parent'])->assertNotFound();
        $foreign = Entity::create(['code' => 'OTHER', 'name' => 'Other', 'entity_type' => 'AFFILIATE', 'tax_id' => 'OTHER']);
        $this->user->update(['entity_id' => $foreign->id]);
        $this->user->unsetRelation('entity');
        $this->putJson("/api/tax-cases/{$this->case->id}/next-action/sp2-records/{$record->id}", ['next_action' => 'Wrong entity'])->assertForbidden();
        $this->assertNull($record->fresh()->next_action);
    }
}

