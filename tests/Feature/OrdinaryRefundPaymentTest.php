<?php

namespace Tests\Feature;

use App\Models\BankTransferRequest;
use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\PreliminaryRefundRequest;
use App\Models\RefundProcess;
use App\Models\Role;
use App\Models\StatusHistory;
use App\Models\TaxCase;
use App\Models\User;
use App\Models\WorkflowHistory;
use App\Services\MainWorkflowStateResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrdinaryRefundPaymentTest extends TestCase
{
    use RefreshDatabase;

    private TaxCase $case;

    private User $staff;

    private User $holding;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $entity = Entity::create(['code' => 'PRA', 'name' => 'Affiliate', 'entity_type' => 'AFFILIATE', 'tax_id' => 'PRA']);
        $holding = Entity::create(['code' => 'PRH', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'PRH']);
        $role = Role::create(['code' => 'STAFF', 'name' => 'Staff', 'permissions' => ['submit_cases'], 'is_active' => true]);
        $this->staff = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
        $this->holding = User::factory()->create(['entity_id' => $holding->id, 'role_id' => $role->id, 'is_active' => true]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        $status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        CaseStatus::create(['code' => 'CLOSED', 'name' => 'Closed']);
        $this->case = TaxCase::create(['entity_id' => $entity->id, 'user_id' => $this->staff->id, 'fiscal_year_id' => $year->id, 'case_number' => 'PRELIMINARY', 'case_type' => 'VAT', 'case_status_id' => $status->id, 'currency_id' => $currency->id, 'spt_position_type' => 'LB', 'spt_type' => 'Restitusi', 'reported_amount' => '1000.00', 'disputed_amount' => '1000.00', 'current_stage' => 1]);
        WorkflowHistory::create(['tax_case_id' => $this->case->id, 'stage_id' => 1, 'action' => 'submitted', 'status' => 'submitted', 'user_id' => $this->staff->id]);
        $this->base = '/api/tax-cases/'.$this->case->id;
        $this->actingAs($this->staff);
    }

    private function refund(int $stage = 4, bool $submittedKian = true): RefundProcess
    {
        $type = [4 => \App\Models\SkpRecord::class, 7 => \App\Models\ObjectionDecision::class, 10 => \App\Models\AppealDecision::class, 12 => \App\Models\SupremeCourtDecision::class][$stage];
        $fields = $stage === 4
            ? ['skp_number' => 'SKP-ORD', 'skp_type' => 'LB', 'skp_amount' => '800.12', 'issue_date' => '2026-11-01']
            : ['decision_number' => 'DEC-'.$stage, 'decision_date' => '2026-11-01', 'decision_type' => 'partially_granted', 'decision_amount' => '800.12'];
        $decision = $type::create($fields + ['tax_case_id' => $this->case->id, 'create_refund' => true, 'refund_amount' => '800.12']);
        WorkflowHistory::create(['tax_case_id' => $this->case->id, 'stage_id' => $stage, 'action' => 'submitted', 'status' => 'submitted', 'user_id' => $this->staff->id]);
        if ($stage === 4 && $submittedKian) {
            \App\Models\KianSubmission::create(['tax_case_id' => $this->case->id, 'stage_id' => 4, 'status' => 'submitted', 'kian_number' => 'ORD-KIAN', 'submission_date' => '2026-11-01', 'kian_amount' => '199.88']);
        }

        return app(\App\Services\DecisionPointRefundService::class)->createIfRequested($decision, $stage, $this->staff->id);
    }

    private function url(RefundProcess $refund, int $stage): string
    {
        return $this->base.'/refunds/'.$refund->id.'/refund-stages/'.$stage;
    }

    private function payload(int $stage): array
    {
        return match ($stage) {
            2 => ['request_number' => 'REQ-1', 'request_date' => '2026-11-01', 'transfer_date' => '2026-11-02'],
            3 => ['instruction_number' => 'INS-1', 'instruction_issue_date' => '2026-11-02', 'instruction_received_date' => '2026-11-03', 'transfer_amount' => '800.12'],
            4 => ['receipt_number' => 'RCPT-1', 'received_date' => '2026-11-04', 'received_amount' => '800.12'],
        };
    }

    private function ready(): RefundProcess
    {
        $refund = $this->refund();
        foreach ([2, 3] as $stage) {
            $this->postJson($this->url($refund, $stage), $this->payload($stage))->assertOk();
        }

        return $refund;
    }

    public function test_stage_four_kian_gate_blocks_payment_and_creation_but_preserves_selected_reads(): void
    {
        $refund = $this->refund(4, false);
        $before = $refund->fresh()->getAttributes();
        $this->getJson($this->url($refund, 1))->assertOk()->assertJsonPath('data.id', $refund->id);
        $this->getJson($this->url($refund, 2))->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertUnprocessable()->assertJsonValidationErrors('ordinary_refund');
        foreach (['refund-stages/1', 'refund-processes'] as $path) {
            $this->postJson($this->base.'/'.$path, ['stage_id' => 4])->assertConflict();
        }
        $this->postJson($this->base.'/refund-processes/'.$refund->id.'/approve')->assertConflict();
        $this->assertSame($before, $refund->fresh()->getAttributes());
        $this->assertDatabaseCount('bank_transfer_requests', 0);

        $kian = \App\Models\KianSubmission::create(['tax_case_id' => $this->case->id, 'stage_id' => 4, 'status' => 'draft', 'kian_number' => 'GATE-K', 'submission_date' => '2026-11-01', 'kian_amount' => '199.88']);
        $this->getJson($this->url($refund, 2))->assertOk()->assertJsonPath('data.can_submit', false);
        $kian->update(['status' => 'submitted']);
        $this->getJson($this->url($refund, 2))->assertOk()->assertJsonPath('data.can_submit', true);
        $this->actingAs($this->holding); // Existing HOLDING entitlement still applies.
        $this->getJson($this->url($refund, 2))->assertOk()->assertJsonPath('data.can_submit', true);
        $this->staff->role->update(['permissions' => []]);
        $this->actingAs($this->staff->fresh());
        $this->getJson($this->url($refund, 2))->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertForbidden();
        $this->staff->role->update(['permissions' => ['submit_cases']]);
        $this->actingAs($this->staff->fresh());
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertOk();
    }

    public function test_stage_four_without_kian_requirement_keeps_payment_eligibility(): void
    {
        $refund = $this->refund(4, false);
        $this->case->skpRecord->update(['skp_amount' => '1000.00']);
        $this->assertFalse($this->case->fresh()->needsKianAtStage(4));
        $this->getJson($this->url($refund, 2))->assertOk()->assertJsonPath('data.can_submit', true);
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertOk();
    }

    public static function stageReadHistories(): array
    {
        $cases = [];
        foreach ([false, true] as $preliminary) {
            foreach ([[0, 0], [1, 0], [2, 0], [60, 0], [0, 1], [1, 1], [60, 2]] as [$live, $deleted]) {
                $cases[($preliminary ? 'preliminary' : 'ordinary')." live=$live deleted=$deleted"] = [$live, $deleted, $preliminary];
            }
        }

        return $cases;
    }

    #[DataProvider('stageReadHistories')]
    public function test_stage_reads_bound_materialization_and_preserve_selected_identity(int $live, int $deleted, bool $preliminary): void
    {
        // Synthetic data in the disposable runner's SQLite :memory: database.
        // No payment commands or historical verification fixtures are used here.
        $a = $this->refund();
        $b = $this->refund(7);
        $a->forceFill(['created_at' => '2026-10-01', 'refund_status' => $live + $deleted ? 'processed' : 'pending'])->save();
        $b->forceFill(['created_at' => '2026-10-02', 'refund_status' => 'processed'])->save();
        if ($preliminary) {
            foreach ([$a, $b] as $refund) {
                $refund->update(['stage_id' => 0, 'stage_source' => 'PRELIMINARY']);
            }
        }
        foreach ([[$a, $live, $deleted], [$b, 1, 0]] as [$refund, $activeCount, $deletedCount]) {
            for ($i = 0; $i < $activeCount + $deletedCount; $i++) {
                // Last two live records tie on created_at. Lowest ID wins the tie;
                // the newer deleted rows must never become display evidence.
                $date = $i >= max(0, $activeCount - 2) ? '2026-11-02' : '2026-11-01';
                \Illuminate\Support\Facades\DB::table('bank_transfer_requests')->insert([
                    'refund_process_id' => $refund->id, 'request_number' => 'REQ-'.$refund->id.'-'.$i,
                    'transfer_number' => 'TR-'.$refund->id.'-'.$i, 'request_date' => '2026-11-01',
                    'transfer_date' => '2026-11-02', 'transfer_amount' => '800.12', 'transfer_status' => 'processing',
                    'instruction_number' => 'INS-'.$refund->id.'-'.$i, 'instruction_issue_date' => '2026-11-02',
                    'instruction_received_date' => '2026-11-03', 'created_by' => $this->staff->id,
                    'created_at' => $i >= $activeCount ? '2026-11-04' : $date, 'updated_at' => $date,
                    'deleted_at' => $i >= $activeCount ? '2026-11-04' : null,
                ]);
            }
        }

        $db = \Illuminate\Support\Facades\DB::connection();
        $dispatcher = BankTransferRequest::getEventDispatcher();
        BankTransferRequest::setEventDispatcher(clone $dispatcher);
        $hydrated = 0;
        BankTransferRequest::retrieved(function () use (&$hydrated) { $hydrated++; });
        $db->enableQueryLog();
        try {
            // Null means the existing read-only latest-Refund compatibility route.
            foreach ([[$a, $a->id, $live, $deleted], [$b, $b->id, 1, 0], [$b, null, 1, 0]] as [$refund, $selectedId, $activeCount, $deletedCount]) {
                foreach ([1, 2, 3, 4] as $stage) {
                    $db->flushQueryLog();
                    $hydrated = 0;
                    $url = $selectedId === null ? $this->base.'/refund-stages/'.$stage : $this->url($refund, $stage);
                    $data = $this->getJson($url)->assertOk()->json('data');
                    $this->assertSame($refund->id, $data[$stage === 1 ? 'id' : 'refund_id']);
                    $this->assertSame($refund->refund_number, $data['refund_number']);
                    $this->assertSame($activeCount ? 3 : 1, $data['current_stage']);
                    $this->assertArrayNotHasKey('bank_transfer_requests', $data);
                    $keys = match ($stage) {
                        1 => ['id', 'refund_number', 'refund_amount', 'refund_method', 'refund_status', 'stage_label', 'is_preliminary', 'current_stage', 'submitted_by_name', 'submitted_at', 'notes'],
                        2 => ['refund_id', 'refund_number', 'refund_amount', 'refund_method', 'refund_status', 'current_stage', 'can_submit'],
                        3 => ['refund_id', 'is_preliminary', 'refund_number', 'refund_amount', 'current_stage', 'can_submit'],
                        4 => ['refund_id', 'refund_number', 'refund_amount', 'refund_method', 'is_preliminary', 'refund_status', 'current_stage', 'can_submit'],
                    };
                    if ($activeCount && $stage >= 2) {
                        $keys = array_merge($keys, ['transfer_number', 'transfer_date', 'transfer_amount', 'transfer_status', 'notes']);
                        if ($stage <= 3) {
                            $keys = array_merge($keys, ['request_number', 'request_date']);
                        }
                        if ($stage >= 3) {
                            $keys = array_merge($keys, ['instruction_number', 'instruction_issue_date', 'instruction_received_date']);
                            $keys = array_merge($keys, $stage === 3
                                ? ['bank_code', 'bank_name', 'account_number', 'account_holder', 'account_name']
                                : ['receipt_number', 'received_date', 'received_amount']);
                        }
                    }
                    $keys[] = 'currency_code';
                    if ($stage === 2) $keys[] = 'is_preliminary';
                    $this->assertSame('IDR', $data['currency_code']);
                    $actualKeys = array_keys($data);
                    sort($keys);
                    sort($actualKeys);
                    $this->assertSame($keys, $actualKeys);
                    if ($stage >= 2) {
                        $this->assertSame($preliminary || ($activeCount + $deletedCount === 0 ? $stage === 2 : ($activeCount === 1 && $deletedCount === 0 && $stage === 4)), $data['can_submit']);
                        if ($activeCount) {
                            $index = max(0, $activeCount - 2);
                            $this->assertSame('TR-'.$refund->id.'-'.$index, $data['transfer_number']);
                            $this->assertSame('800.12', $data['transfer_amount']);
                            if ($stage >= 3) {
                                $this->assertSame('INS-'.$refund->id.'-'.$index, $data['instruction_number']);
                            }
                        } else {
                            $this->assertArrayNotHasKey('transfer_number', $data);
                        }
                    }
                    if ($stage === 4) {
                        $this->assertSame('bank_transfer', $data['refund_method']);
                        $this->assertSame($preliminary, $data['is_preliminary']);
                    }
                    $queries = collect($db->getQueryLog())->pluck('query');
                    $this->assertLessThanOrEqual(1, $queries->filter(fn ($sql) => str_contains($sql, 'from "currencies"'))->count());
                    $transfers = $queries->filter(fn ($sql) => str_contains($sql, 'from "bank_transfer_requests"'))->values();
                    $ordinaryEligibility = ! $preliminary && $stage >= 2;
                    $this->assertCount($ordinaryEligibility ? 2 : 1, $transfers);
                    $this->assertStringContainsString('"deleted_at" is null', $transfers[0]);
                    $this->assertStringContainsString('order by "created_at" desc, "id" asc limit 1', $transfers[0]);
                    if ($ordinaryEligibility) {
                        $this->assertStringContainsString('order by "id" asc limit 2', $transfers[1]);
                        $this->assertStringNotContainsString('"deleted_at" is null', $transfers[1]);
                    }
                    $this->assertSame(min(1, $activeCount) + ($ordinaryEligibility ? min(2, $activeCount + $deletedCount) : 0), $hydrated);
                    $this->assertTrue($queries->every(fn ($sql) => str_starts_with(strtolower($sql), 'select ')));
                }
            }
            foreach ([1, 2, 3, 4] as $stage) {
                $other = $this->case->replicate();
                $other->case_number = 'C3-FOREIGN-'.$stage;
                $other->save();
                $this->getJson('/api/tax-cases/'.$other->id.'/refunds/'.$a->id.'/refund-stages/'.$stage)->assertNotFound();
                $this->getJson($this->base.'/refunds/999999/refund-stages/'.$stage)->assertNotFound();
            }
        } finally {
            $db->disableQueryLog();
            BankTransferRequest::setEventDispatcher($dispatcher);
        }
    }

    public function test_changed_origin_amount_disables_new_request_without_mutation(): void
    {
        $refund = $this->refund();
        $refund->update(['refund_amount' => '900.12']);
        $before = $refund->fresh()->getAttributes();
        $caseBefore = $this->case->fresh()->getAttributes();
        $read = $this->getJson($this->url($refund, 2))->assertOk();
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertUnprocessable();
        $this->assertDatabaseCount('bank_transfer_requests', 0);
        $this->assertSame($before, $refund->fresh()->getAttributes());
        $this->assertSame($caseBefore, $this->case->fresh()->getAttributes());
        $read->assertJsonPath('data.can_submit', false);
    }

    public function test_missing_instruction_disables_new_receipt_without_mutation(): void
    {
        $refund = $this->ready();
        $transfer = BankTransferRequest::sole();
        $transfer->update(['instruction_number' => null]);
        $before = $transfer->fresh()->getAttributes();
        $refundBefore = $refund->fresh()->getAttributes();
        $caseBefore = $this->case->fresh()->getAttributes();
        $read = $this->getJson($this->url($refund, 4))->assertOk();
        $this->postJson($this->url($refund, 4), $this->payload(4))->assertUnprocessable();
        $this->assertSame($before, $transfer->fresh()->getAttributes());
        $this->assertSame($refundBefore, $refund->fresh()->getAttributes());
        $this->assertSame($caseBefore, $this->case->fresh()->getAttributes());
        $read->assertJsonPath('data.can_submit', false);
    }

    public function test_selected_refund_full_payment_retries_and_no_case_side_effect(): void
    {
        $a = $this->refund();
        $b = $this->refund(7);
        $caseBefore = $this->case->fresh()->getAttributes();
        $otherBefore = $b->fresh()->getAttributes();
        $historyCount = WorkflowHistory::count();
        foreach ([2, 3, 4] as $stage) {
            $url = $this->url($a, $stage);
            $this->getJson($url)->assertOk()->assertJsonPath('data.can_submit', true);
            $this->postJson($url, $this->payload($stage))->assertOk();
            $this->getJson($url)->assertOk()->assertJsonPath('data.can_submit', false);
            $this->getJson($this->url($b, 2))->assertOk()->assertJsonPath('data.can_submit', true);
            $transferBefore = BankTransferRequest::sole()->getAttributes();
            $refundBefore = $a->fresh()->getAttributes();
            $this->postJson($url, $this->payload($stage))->assertOk();
            $this->assertSame($transferBefore, BankTransferRequest::sole()->getAttributes());
            $this->assertSame($refundBefore, $a->fresh()->getAttributes());
            $conflict = $this->payload($stage);
            $conflict[array_key_first($conflict)] = 'CONFLICT';
            $this->postJson($url, $conflict)->assertUnprocessable();
            $this->assertSame($transferBefore, BankTransferRequest::sole()->getAttributes());
        }
        foreach ([2, 3, 4] as $stage) {
            $this->getJson($this->url($a, $stage))->assertOk()->assertJsonPath('data.can_submit', false);
            $this->postJson($this->url($a, $stage), $this->payload($stage))->assertOk();
            $this->assertSame($transferBefore, BankTransferRequest::sole()->getAttributes());
            $this->assertSame($refundBefore, $a->fresh()->getAttributes());
        }
        $this->assertSame('completed', $a->fresh()->refund_status);
        $this->assertSame('800.12', BankTransferRequest::sole()->received_amount);
        $this->assertSame($otherBefore, $b->fresh()->getAttributes());
        $this->assertSame($caseBefore, $this->case->fresh()->getAttributes());
        $this->assertSame($historyCount, WorkflowHistory::count());
        $this->assertDatabaseCount('bank_transfer_requests', 1);
        Queue::assertNothingPushed();
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($v) => [$v], ['400.06', '800.11', '800.13', '880.13', '-1', '0', 'no', '800.120', '8.0012e2', null, 800.12]);
    }

    #[DataProvider('invalidAmounts')]
    public function test_partial_overpay_and_malformed_amounts_are_atomic(mixed $amount): void
    {
        $refund = $this->ready();
        $before = BankTransferRequest::sole()->getAttributes();
        $refundBefore = $refund->fresh()->getAttributes();
        $this->postJson($this->url($refund, 4), array_replace($this->payload(4), ['received_amount' => $amount]))->assertUnprocessable();
        $this->assertSame($before, BankTransferRequest::sole()->getAttributes());
        $this->assertSame($refundBefore, $refund->fresh()->getAttributes());
    }

    public function test_identity_authorization_and_nested_ownership_have_zero_writes(): void
    {
        $a = $this->ready();
        $b = $this->refund(7);
        $this->postJson($this->url($b, 2), $this->payload(2))->assertOk();
        $before = BankTransferRequest::orderBy('id')->get()->toArray();
        foreach ([2, 3, 4] as $stage) {
            $this->postJson($this->base.'/refund-stages/'.$stage, $this->payload($stage))->assertUnprocessable();
            $this->postJson($this->base.'/refunds/99999/refund-stages/'.$stage, $this->payload($stage))->assertNotFound();
            $this->getJson($this->base.'/refunds/99999/refund-stages/'.$stage)->assertNotFound();
            $this->postJson($this->base.'/refunds/no/refund-stages/'.$stage, $this->payload($stage))->assertUnprocessable();
        }
        $this->postJson($this->url($a, 4), $this->payload(4) + ['refund_process_id' => $b->id])->assertNotFound();
        $this->postJson($this->url($a, 4), $this->payload(4) + ['bank_transfer_request_id' => $b->bankTransferRequests()->sole()->id])->assertNotFound();
        $other = $this->case->replicate();
        $other->case_number = 'OTHER';
        $other->save();
        $this->postJson('/api/tax-cases/'.$other->id.'/refunds/'.$a->id.'/refund-stages/4', $this->payload(4))->assertNotFound();
        $this->getJson('/api/tax-cases/'.$other->id.'/refunds/'.$a->id.'/refund-stages/4')->assertNotFound();
        $entity = Entity::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'entity_type' => 'AFFILIATE', 'tax_id' => 'FOREIGN']);
        $this->staff->update(['entity_id' => $entity->id]);
        $this->postJson($this->url($a, 4), $this->payload(4))->assertForbidden();
        $this->getJson($this->url($a, 4))->assertForbidden();
        $this->assertSame($before, BankTransferRequest::orderBy('id')->get()->toArray());
    }

    public function test_alternative_writers_and_currency_or_status_spoof_are_blocked(): void
    {
        $refund = $this->ready();
        $transfer = BankTransferRequest::sole();
        $before = $transfer->getAttributes();
        $prefix = $this->base.'/refund-processes/'.$refund->id.'/bank-transfers';
        foreach ([$prefix, $prefix.'/'.$transfer->id.'/process', $prefix.'/'.$transfer->id.'/reject'] as $url) {
            $this->postJson($url, $this->payload(4))->assertUnprocessable();
        }
        foreach ([13, 14, 15] as $stage) {
            $this->postJson($this->base.'/workflow/'.$stage, $this->payload(4))->assertUnprocessable();
            $this->postJson($this->base.'/refunds/'.$refund->id.'/workflow/'.$stage, $this->payload(4))->assertUnprocessable();
        }
        foreach (['currency' => 'USD', 'currency_id' => 999, 'transfer_status' => 'completed', 'transfer_amount' => '999.00'] as $key => $value) {
            $this->postJson($this->url($refund, 4), $this->payload(4) + [$key => $value])->assertUnprocessable();
        }
        $this->assertSame($before, $transfer->fresh()->getAttributes());
    }

    public function test_predecessors_immutable_amount_notes_and_historical_duplicates(): void
    {
        $refund = $this->refund();
        foreach ([3, 4] as $stage) {
            $this->postJson($this->url($refund, $stage), $this->payload($stage))->assertUnprocessable();
        }
        $this->assertDatabaseCount('bank_transfer_requests', 0);
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertOk();
        $this->postJson($this->url($refund, 4), $this->payload(4))->assertUnprocessable();
        $before = BankTransferRequest::sole()->getAttributes();
        $this->postJson($this->url($refund, 3), array_replace($this->payload(3), ['transfer_amount' => '900.12']))->assertUnprocessable();
        $this->postJson($this->url($refund, 3), $this->payload(3) + ['notes' => 'Replace prior evidence'])->assertUnprocessable();
        $this->assertSame($before, BankTransferRequest::sole()->getAttributes());
        $duplicate = BankTransferRequest::sole()->replicate();
        $duplicate->save();
        $duplicate->delete();
        $this->postJson($this->url($refund, 3), $this->payload(3))->assertUnprocessable();
        $this->assertDatabaseCount('bank_transfer_requests', 2);
        $this->assertSame($before, BankTransferRequest::first()->getAttributes());
    }

    public function test_missing_decision_or_changed_approval_is_readonly(): void
    {
        $refund = $this->refund();
        $refund->update(['refund_amount' => '900.12']);
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertUnprocessable();
        $refund->update(['refund_amount' => '800.12']);
        WorkflowHistory::where('stage_id', 4)->update(['status' => 'draft']);
        $this->getJson($this->url($refund, 2))->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertUnprocessable();
        $this->assertDatabaseCount('bank_transfer_requests', 0);
    }

    public function test_inactive_or_no_capability_and_guest_cannot_pay(): void
    {
        $refund = $this->ready();
        $this->staff->update(['is_active' => false]);
        $this->getJson($this->url($refund, 4))->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson($this->url($refund, 4), $this->payload(4))->assertForbidden();
        $this->staff->update(['is_active' => true]);
        $this->staff->role->update(['permissions' => []]);
        $this->getJson($this->url($refund, 4))->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson($this->url($refund, 4), $this->payload(4))->assertForbidden();
        auth()->forgetGuards();
        $this->postJson($this->url($refund, 4), $this->payload(4))->assertUnauthorized();
        $this->assertNull(BankTransferRequest::sole()->received_amount);
    }

    public static function inconsistentEvidence(): array
    {
        return [
            'request number' => [3, 'transfer', ['request_number' => '']],
            'request date' => [3, 'transfer', ['request_date' => null]],
            'transfer date' => [4, 'transfer', ['transfer_date' => null]],
            'instruction issue date' => [4, 'transfer', ['instruction_issue_date' => null]],
            'instruction received date' => [4, 'transfer', ['instruction_received_date' => null]],
            'early instruction' => [3, 'transfer', ['instruction_number' => 'EARLY']],
            'early instruction date' => [3, 'transfer', ['instruction_issue_date' => '2026-11-02']],
            'early receipt' => [3, 'transfer', ['receipt_number' => 'EARLY']],
            'early receipt amount' => [4, 'transfer', ['received_amount' => '800.12']],
            'adjusted transfer' => [3, 'transfer', ['transfer_amount' => '900.12']],
            'invalid transfer status' => [4, 'transfer', ['transfer_status' => 'pending']],
            'invalid refund status' => [3, 'refund', ['refund_status' => 'processed']],
            'stale instruction approval' => [3, 'refund', ['refund_amount' => '900.12']],
            'stale receipt approval' => [4, 'refund', ['refund_amount' => '900.12']],
            'missing origin' => [2, 'refund', ['triggered_by_decision_id' => null]],
            'unknown origin' => [2, 'refund', ['triggered_by_decision_id' => 99999]],
            'wrong concrete origin' => [2, 'refund', ['triggered_by_decision_type' => \App\Models\AppealDecision::class]],
            'wrong source' => [2, 'refund', ['stage_source' => 'APPEAL']],
            'wrong method' => [2, 'refund', ['refund_method' => 'cash']],
            'unrequested origin' => [2, 'decision', ['create_refund' => false]],
            'changed decision amount' => [2, 'decision', ['refund_amount' => '900.12']],
            'foreign decision' => [2, 'foreign', []],
            'deleted transfer' => [3, 'deleted', []],
            'extra active transfer' => [4, 'duplicate', []],
            'extra deleted transfer' => [3, 'deleted_duplicate', []],
        ];
    }

    #[DataProvider('inconsistentEvidence')]
    public function test_inconsistent_persisted_evidence_is_unavailable_and_readonly(int $stage, string $target, array $changes): void
    {
        $refund = $this->refund();
        for ($prior = 2; $prior < $stage; $prior++) {
            $this->postJson($this->url($refund, $prior), $this->payload($prior))->assertOk();
        }
        $transfer = $refund->bankTransferRequests()->first();
        $decision = \App\Models\SkpRecord::findOrFail($refund->triggered_by_decision_id);
        if ($target === 'refund') {
            $refund->update($changes);
        } elseif ($target === 'decision') {
            $decision->update($changes);
        } elseif ($target === 'foreign') {
            $other = $this->case->replicate();
            $other->case_number = 'FOREIGN-ORIGIN';
            $other->save();
            $decision->update(['tax_case_id' => $other->id]);
        } elseif ($target === 'transfer') {
            $transfer->update($changes);
        } elseif ($target === 'deleted') {
            $transfer->delete();
        } else {
            $duplicate = $transfer->replicate();
            $duplicate->save();
            if ($target === 'deleted_duplicate') {
                $duplicate->delete();
            }
        }
        $before = BankTransferRequest::withTrashed()->orderBy('id')->get()->toArray();
        $refundBefore = $refund->fresh()->getAttributes();
        $caseBefore = $this->case->fresh()->getAttributes();
        $this->getJson($this->url($refund, $stage))->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson($this->url($refund, $stage), $this->payload($stage))->assertStatus(in_array($target, ['foreign'], true) || $changes === ['triggered_by_decision_id' => 99999] ? 404 : 422);
        $this->assertSame($before, BankTransferRequest::withTrashed()->orderBy('id')->get()->toArray());
        $this->assertSame($refundBefore, $refund->fresh()->getAttributes());
        $this->assertSame($caseBefore, $this->case->fresh()->getAttributes());
    }

    public function test_inactive_role_cannot_gain_availability_or_write(): void
    {
        $refund = $this->refund();
        $this->staff->role->update(['is_active' => false]);
        $this->getJson($this->url($refund, 2))->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson($this->url($refund, 2), $this->payload(2))->assertForbidden();
        $this->assertDatabaseCount('bank_transfer_requests', 0);
    }

    public function test_eligibility_uses_one_bounded_transfer_query_without_writes(): void
    {
        $refund = $this->ready()->fresh();
        $db = \Illuminate\Support\Facades\DB::connection();
        $db->enableQueryLog();
        $db->flushQueryLog();
        try {
            $this->assertTrue(app(\App\Services\OrdinaryRefundPayment::class)->canSubmit($this->case, $refund, 4));
            $queries = collect($db->getQueryLog())->pluck('query');
            $transfers = $queries->filter(fn ($sql) => str_contains($sql, 'bank_transfer_requests'))->values();
            $this->assertCount(1, $transfers);
            $this->assertStringContainsString('limit 2', $transfers[0]);
            $this->assertStringNotContainsString('deleted_at" is null', $transfers[0]);
            $this->assertTrue($queries->every(fn ($sql) => str_starts_with(strtolower($sql), 'select ')));
        } finally {
            $db->disableQueryLog();
        }
    }

    public function test_stage_twelve_terminal_case_can_settle_without_main_workflow_change(): void
    {
        $refund = $this->refund(12);
        $this->case->update(['current_stage' => 12, 'is_completed' => true]);
        $before = $this->case->fresh()->getAttributes();
        foreach ([2, 3, 4] as $stage) {
            $this->postJson($this->url($refund, $stage), $this->payload($stage))->assertOk();
        }
        $this->assertSame($before, $this->case->fresh()->getAttributes());
    }

    public function test_stage_ten_payment_and_failure_rolls_back_both_related_writes(): void
    {
        $refund = $this->refund(10);
        $dispatcher = RefundProcess::getEventDispatcher();
        // Isolate the injected failure from other tests and production listeners.
        RefundProcess::setEventDispatcher(clone $dispatcher);
        RefundProcess::updating(function () { throw new \RuntimeException('Injected Refund persistence failure'); });
        try {
            app(\App\Services\OrdinaryRefundPayment::class)->payment($this->case->id, $refund->id, 2, $this->payload(2));
            $this->fail('Expected injected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected Refund persistence failure', $e->getMessage());
        } finally {
            RefundProcess::setEventDispatcher($dispatcher);
        }
        $this->assertDatabaseCount('bank_transfer_requests', 0);
        $this->assertSame('pending', $refund->fresh()->refund_status);
        foreach ([2, 3, 4] as $stage) {
            $this->postJson($this->url($refund, $stage), $this->payload($stage))->assertOk();
        }
    }
}

