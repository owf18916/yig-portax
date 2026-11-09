<?php

namespace Tests\Feature;

use App\Models\{AppealDecision, AuditLog, CaseStatus, Currency, Document, Entity, FiscalYear, ObjectionDecision, Role, SkpRecord, SupremeCourtDecision, TaxCase, User, WorkflowHistory};
use App\Services\{MainStageFollowUp, MainWorkflowStateResolver};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Mail, Queue};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StageTwelveFollowUpCorrectiveTest extends TestCase
{
    use RefreshDatabase;

    private TaxCase $case;
    private User $user;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame('sqlite', \Illuminate\Support\Facades\DB::connection()->getDriverName());
        $this->assertSame(':memory:', \Illuminate\Support\Facades\DB::connection()->getDatabaseName());
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        $entity = Entity::create(['code' => 'S12', 'name' => 'Stage Twelve', 'entity_type' => 'AFFILIATE', 'tax_id' => 'S12']);
        // The existing close action checks role_id 1; preserve that contract.
        $role = Role::forceCreate(['id' => 1, 'code' => 'ADMIN', 'name' => 'Admin']);
        $this->user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        CaseStatus::forceCreate(['id' => 1, 'code' => 'OPEN', 'name' => 'Open']);
        CaseStatus::forceCreate(['id' => 2, 'code' => 'SUBMITTED', 'name' => 'Submitted']);
        CaseStatus::forceCreate(['id' => 3, 'code' => 'CLOSED', 'name' => 'Closed']);
        $this->case = TaxCase::create(['user_id' => $this->user->id, 'entity_id' => $entity->id,
            'fiscal_year_id' => $year->id, 'currency_id' => $currency->id, 'case_status_id' => 1,
            'case_number' => 'S12-2026', 'case_type' => 'CIT', 'spt_position_type' => 'LB',
            'reported_amount' => 100, 'disputed_amount' => 100, 'current_stage' => 12]);
        foreach (range(1, 11) as $stage) {
            WorkflowHistory::create(['tax_case_id' => $this->case->id, 'stage_id' => $stage,
                'action' => 'submitted', 'status' => 'submitted', 'user_id' => $this->user->id]);
        }
        SkpRecord::create(['tax_case_id' => $this->case->id, 'skp_type' => 'KB', 'skp_amount' => 100, 'continue_to_next_stage' => true]);
        ObjectionDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'OBJ-S12', 'decision_date' => '2026-11-01', 'decision_type' => 'rejected', 'decision_amount' => 0,
            'continue_to_next_stage' => true, 'status' => 'submitted']);
        AppealDecision::create(['tax_case_id' => $this->case->id, 'decision_number' => 'APL-S12', 'decision_date' => '2026-11-01', 'decision_type' => 'rejected', 'decision_amount' => 0,
            'continue_to_next_stage' => true, 'status' => 'submitted']);
        Document::create(['documentable_type' => TaxCase::class, 'documentable_id' => $this->case->id,
            'tax_case_id' => $this->case->id, 'document_type' => 'supporting_document', 'stage_code' => '12',
            'original_filename' => 'stage12.pdf', 'file_path' => 'tests/stage12.pdf', 'file_mime_type' => 'application/pdf',
            'file_size' => 1, 'uploaded_by' => $this->user->id, 'uploaded_at' => now(), 'status' => 'ACTIVE']);
        $this->actingAs($this->user);
    }

    private function submit(string $path = 'workflow/12'): SupremeCourtDecision
    {
        $this->postJson("/api/tax-cases/{$this->case->id}/{$path}", ['action' => 'submit',
            'decision_number' => 'S12-DEC', 'decision_date' => '2026-11-09', 'decision_type' => 'granted',
            'decision_amount' => 200, 'create_refund' => false])->assertSuccessful();

        return $this->case->fresh()->supremeCourtDecision;
    }

    private function url(SupremeCourtDecision $record): string
    {
        return "/api/tax-cases/{$this->case->id}/next-action/supreme-court-decisions/{$record->id}";
    }

    public static function submissionPaths(): array
    {
        return [['workflow/12'], ['supreme-court-decisions']];
    }

    #[DataProvider('submissionPaths')]
    public function test_both_submission_paths_are_terminal_open_with_independent_operational_writes(string $path): void
    {
        $record = $this->submit($path);
        $case = $this->case->fresh();
        $this->assertTrue($case->is_completed);
        $this->assertFalse($case->explicitly_closed);
        $state = app(MainWorkflowStateResolver::class)->resolve($case);
        $this->assertTrue($state['terminal']);
        $this->assertSame('open', $state['closure_state']);
        $this->assertSame([], $state['available_stages']);
        $this->assertSame(12, $state['current_stage']);
        $this->getJson("/api/tax-cases/{$case->id}")->assertOk()
            ->assertJsonPath('data.can_update_stage12_follow_up', true)
            ->assertJsonPath('data.can_update_follow_up', false);
        $history = WorkflowHistory::all()->toArray();
        $business = $record->getAttributes();
        foreach ([null, 'refund', 'kian'] as $enum) {
            $record->update(['next_action' => $enum]);
            $this->putJson($this->url($record), ['follow_up_next_action' => 'Call court '.($enum ?? 'unset'),
                'next_action_due_date' => '2026-12-01', 'status_comment' => 'Waiting'])->assertOk();
            $saved = $record->fresh();
            $this->assertSame($enum, $saved->next_action);
            $this->assertSame('Call court '.($enum ?? 'unset'), $saved->follow_up_next_action);
            $this->assertSame('2026-12-01', $saved->next_action_due_date->toDateString());
            $this->assertSame('Waiting', $saved->status_comment);
        }
        $this->putJson($this->url($record), ['status_comment' => 'Partial'])->assertOk();
        $this->assertSame('Call court kian', $record->fresh()->follow_up_next_action);
        $this->putJson($this->url($record), ['follow_up_next_action' => null, 'next_action_due_date' => null, 'status_comment' => ''])->assertOk();
        $this->assertNull($record->fresh()->follow_up_next_action);
        $this->assertNull($record->fresh()->next_action_due_date);
        $this->assertNull($record->fresh()->status_comment);
        $this->assertSame('kian', $record->fresh()->next_action);
        foreach ($business as $key => $value) {
            if (! in_array($key, ['next_action', 'updated_at', 'follow_up_next_action', 'next_action_due_date', 'status_comment'])) {
                $this->assertSame($value, $record->fresh()->getAttributes()[$key], $key);
            }
        }
        $this->assertSame($history, WorkflowHistory::all()->toArray());
        $this->postJson("/api/tax-cases/{$case->id}/workflow/13", ['action' => 'submit'])->assertUnprocessable();
        $this->postJson("/api/tax-cases/{$case->id}/workflow/12", ['action' => 'draft'])->assertConflict();
        foreach (['refund_processes', 'kian_submissions', 'revisions'] as $table) $this->assertDatabaseCount($table, 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public static function closePaths(): array
    {
        return [['close'], ['complete']];
    }

    #[DataProvider('closePaths')]
    public function test_actual_deliberate_closure_blocks_api_and_stale_bound_models_idempotently(string $path): void
    {
        $record = $this->submit();
        $stale = $this->case->fresh();
        $url = "/api/tax-cases/{$this->case->id}/{$path}";
        $this->postJson($url)->assertOk();
        $before = $this->case->fresh()->getAttributes();
        $this->travel(2)->seconds();
        $this->postJson($url)->assertOk();
        $this->assertSame($before, $this->case->fresh()->getAttributes());
        $this->assertTrue($this->case->fresh()->explicitly_closed);
        $this->putJson($this->url($record), ['follow_up_next_action' => 'After close',
            'next_action_due_date' => '2026-12-01', 'status_comment' => 'After close'])->assertConflict();
        $this->getJson("/api/tax-cases/{$this->case->id}")->assertOk()->assertJsonPath('data.can_update_stage12_follow_up', false);
        $request = Request::create('/', 'PUT', ['follow_up_next_action' => 'Stale read']);
        $request->setUserResolver(fn () => $this->user);
        try {
            app(MainStageFollowUp::class)->update($request, $stale, SupremeCourtDecision::class, $record->id, 12);
            $this->fail('Stale bound case bypassed closure');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertNull($record->fresh()->follow_up_next_action);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_legacy_rows_status_and_timestamp_closures_never_gain_access(): void
    {
        $record = SupremeCourtDecision::create(['tax_case_id' => $this->case->id, 'status' => 'submitted']);
        foreach ([false, true] as $flag) {
            $this->case->forceFill(['is_completed' => $flag, 'explicitly_closed' => null])->save();
            $this->putJson($this->url($record), ['follow_up_next_action' => 'Legacy'])->assertConflict();
            $this->assertNull($this->case->fresh()->explicitly_closed);
            $this->getJson("/api/tax-cases/{$this->case->id}")->assertOk()
                ->assertJsonPath('data.workflow_state.closure_state', 'legacy_unresolved');
        }
        foreach ([['case_status_id' => 3], ['completed_date' => '2026-11-09']] as $closure) {
            $this->case->forceFill(['explicitly_closed' => false, 'is_completed' => true, 'case_status_id' => 1, 'completed_date' => null] + $closure);
            // Override defaults deliberately, including contradictory confirmed-open provenance.
            $this->case->forceFill($closure)->save();
            $this->putJson($this->url($record), ['status_comment' => 'Closed'])->assertConflict();
        }
    }

    public function test_draft_approval_confirms_open_submission_but_never_reopens_a_closed_case(): void
    {
        $record = SupremeCourtDecision::create(['tax_case_id' => $this->case->id, 'status' => 'draft',
            'decision_type' => 'granted', 'decision_amount' => 200]);
        $this->postJson("/api/tax-cases/{$this->case->id}/supreme-court-decisions/{$record->id}/approve")->assertOk();
        $this->assertFalse($this->case->fresh()->explicitly_closed);
        $this->putJson($this->url($record), ['status_comment' => 'Approved follow-up'])->assertOk();
        // Another case with a draft must remain closed even when approval is called directly.
        $this->case->forceFill(['explicitly_closed' => true])->save();
        $record->refresh()->update(['status' => 'draft']);
        WorkflowHistory::where('stage_id', 12)->delete();
        $this->postJson("/api/tax-cases/{$this->case->id}/supreme-court-decisions/{$record->id}/approve")->assertConflict();
        $this->assertTrue($this->case->fresh()->explicitly_closed);
        $this->assertSame('draft', $record->fresh()->status);
    }

    public function test_validation_enum_protection_entity_policy_and_parent_identity(): void
    {
        $record = $this->submit();
        foreach ([['next_action' => 'refund'], ['next_action' => 'arbitrary text'], ['decision_amount' => 9], ['explicitly_closed' => false],
            ['follow_up_next_action' => str_repeat('a', 1001)], ['status_comment' => str_repeat('a', 1001)],
            ['next_action_due_date' => '2026-02-30'], ['next_action_due_date' => 'tomorrow'], ['follow_up_next_action' => []]] as $payload) {
            $this->putJson($this->url($record), $payload)->assertUnprocessable();
        }
        $this->putJson($this->url($record), [])->assertOk();
        $this->putJson($this->url($record), ['follow_up_next_action' => str_repeat('a', 1000), 'status_comment' => str_repeat('b', 1000)])->assertOk();
        $other = $this->case->replicate();
        $other->case_number = 'OTHER-S12';
        $other->save();
        $this->putJson("/api/tax-cases/{$other->id}/next-action/supreme-court-decisions/{$record->id}", ['status_comment' => 'Wrong parent'])->assertNotFound();
        $foreign = Entity::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'entity_type' => 'AFFILIATE', 'tax_id' => 'FOREIGN']);
        foreach (['ADMIN', 'STAFF'] as $roleCode) {
            $role = Role::firstOrCreate(['code' => $roleCode], ['name' => $roleCode]);
            $this->user->update(['entity_id' => $foreign->id, 'role_id' => $role->id]);
            $this->user->unsetRelation('entity');
            $this->putJson($this->url($record), ['status_comment' => 'Foreign'])->assertForbidden();
        }
        $foreign->update(['entity_type' => 'HOLDING']);
        $this->user->unsetRelation('entity');
        $this->putJson($this->url($record), ['status_comment' => 'Holding entitled'])->assertOk();
        $this->assertNull($record->fresh()->next_action);
    }

    public function test_audit_actor_time_changes_and_repeats_and_atomic_rollback(): void
    {
        $record = $this->submit();
        $this->freezeTime();
        $this->putJson($this->url($record), ['follow_up_next_action' => 'Call court'])->assertOk();
        $audit = AuditLog::sole();
        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame($record->id, $audit->auditable_id);
        $this->assertSame(SupremeCourtDecision::class, $audit->auditable_type);
        $this->assertSame(['follow_up_next_action' => null], $audit->old_values);
        $this->assertSame(['follow_up_next_action' => 'Call court'], $audit->new_values);
        $this->assertSame(now()->toDateTimeString(), $audit->performed_at->toDateTimeString());
        $this->putJson($this->url($record), ['follow_up_next_action' => 'Call court'])->assertOk();
        $this->assertDatabaseCount('audit_logs', 1);
        $this->putJson($this->url($record), ['follow_up_next_action' => null])->assertOk();
        $cleared = AuditLog::orderByDesc('id')->first();
        $this->assertSame(['follow_up_next_action' => 'Call court'], $cleared->old_values);
        $this->assertSame(['follow_up_next_action' => null], $cleared->new_values);
        AuditLog::creating(function () { throw new \RuntimeException('Audit unavailable'); });
        try {
            $request = Request::create('/', 'PUT', ['status_comment' => 'Must roll back']);
            $request->setUserResolver(fn () => $this->user);
            app(MainStageFollowUp::class)->update($request, $this->case, SupremeCourtDecision::class, $record->id, 12);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        } finally {
            AuditLog::flushEventListeners();
        }
        $this->assertNull($record->fresh()->status_comment);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_export_uses_operational_text_and_never_falls_back_to_enum(): void
    {
        $record = $this->submit();
        $sheet = (new \App\Exports\TaxCasesExport(collect([$this->case->fresh()])))->sheets()[0];
        $method = new \ReflectionMethod($sheet, 'getStageSpecificData');
        foreach ([null, 'refund', 'kian'] as $enum) {
            $record->update(['next_action' => $enum]);
            $this->assertSame('', $method->invoke($sheet, $this->case->fresh(), 12)['next_action']);
        }
        $this->putJson($this->url($record), ['follow_up_next_action' => 'Operational export text'])->assertOk();
        $this->assertSame('Operational export text', $method->invoke($sheet, $this->case->fresh(), 12)['next_action']);
        $this->getJson("/api/tax-cases/{$this->case->id}/supreme-court-decisions")->assertOk()
            ->assertJsonPath('data.next_action', 'kian')->assertJsonPath('data.follow_up_next_action', 'Operational export text');
    }
}
