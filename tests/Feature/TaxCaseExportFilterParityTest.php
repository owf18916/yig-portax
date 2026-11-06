<?php

namespace Tests\Feature;

use App\Exports\TaxCasesExport;
use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\Role;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class TaxCaseExportFilterParityTest extends TestCase
{
    use RefreshDatabase;

    private User $holding;

    private User $affiliate;

    private Entity $a;

    private Entity $b;

    private FiscalYear $year;

    private Currency $currency;

    private CaseStatus $status;

    private int $sequence = 0;

    protected function beforeRefreshingDatabase(): void
    {
        // This hook runs before migrations or fixture mutation.
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);
        foreach (['cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'queue.default' => 'sync', 'broadcasting.default' => null, 'filesystems.default' => 'local'] as $key => $value) {
            $this->assertSame($value, config($key), $key);
        }
        $root = str_replace('\\', '/', storage_path());
        $this->assertStringStartsWith(str_replace('\\', '/', sys_get_temp_dir()).'/portax_auth_', $root);
        foreach (['local', 'public', 'nas'] as $disk) {
            $this->assertStringStartsWith($root.'/', str_replace('\\', '/', config("filesystems.disks.$disk.root")));
        }
        $this->assertStringStartsWith($root.'/', str_replace('\\', '/', config('excel.temporary_files.local_path')));
        $this->assertNull(config('excel.temporary_files.remote_disk'));
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Entity::create(['code' => 'SAI', 'name' => 'Alpha Company', 'entity_type' => 'AFFILIATE', 'tax_id' => 'A']);
        $this->b = Entity::create(['code' => 'BBB', 'name' => 'Beta Company', 'entity_type' => 'AFFILIATE', 'tax_id' => 'B']);
        $holding = Entity::create(['code' => 'HOLD', 'name' => 'Holding', 'entity_type' => 'HOLDING', 'tax_id' => 'H']);
        $role = Role::create(['code' => 'STAFF', 'name' => 'Staff']);
        $this->holding = User::factory()->create(['entity_id' => $holding->id, 'role_id' => $role->id]);
        $this->affiliate = User::factory()->create(['entity_id' => $this->a->id, 'role_id' => $role->id]);
        $this->year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->currency = Currency::create(['code' => 'USD', 'name' => 'Dollar', 'symbol' => '$']);
        $this->status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        $this->actingAs($this->holding);
        // Keep actual controller/query/workbook eligibility, substitute only download I/O.
        Excel::shouldReceive('download')->andReturnUsing(function (TaxCasesExport $export, string $filename) {
            $this->assertMatchesRegularExpression('/^tax-cases-export-.*\.xlsx$/', $filename);
            $numbers = [];
            foreach ($export->sheets() as $sheet) {
                foreach ($sheet->collection() as $row) {
                    $numbers[] = $row['Ref #'];
                }
            }

            return response()->json(['numbers' => array_values(array_unique($numbers))]);
        });
    }

    private function make(array $attributes = []): TaxCase
    {
        $n = ++$this->sequence;
        $period = Period::create(['fiscal_year_id' => $this->year->id, 'period_code' => "P$n", 'year' => 2025,
            'month' => ($n % 12) + 1, 'start_date' => '2025-01-01', 'end_date' => '2025-01-31']);

        return TaxCase::create(array_merge([
            'user_id' => $this->affiliate->id, 'entity_id' => $this->a->id,
            'fiscal_year_id' => $this->year->id, 'period_id' => $period->id,
            'currency_id' => $this->currency->id, 'case_status_id' => $this->status->id,
            'case_number' => "CASE-$n", 'case_type' => 'CIT', 'current_stage' => 1,
            'reported_amount' => 1000, 'disputed_amount' => 1000,
        ], $attributes));
    }

    private function history(TaxCase $case, int $stage, ?string $status, string $date = '2026-01-01'): void
    {
        $case->workflowHistories()->create(['stage_id' => $stage, 'status' => $status, 'action' => 'submitted',
            'user_id' => $this->affiliate->id])->forceFill(['created_at' => $date])->save();
    }

    private function parity(array $filters, array $expected): void
    {
        $numbers = [];
        $page = 1;
        do {
            $data = $this->getJson('/api/tax-cases?'.http_build_query($filters + ['page' => $page]))->assertOk()->json('data');
            $numbers = array_merge($numbers, array_column($data['data'], 'case_number'));
        } while ($page++ < $data['last_page']);
        sort($numbers);
        sort($expected);
        $this->assertSame($expected, $numbers);
        $response = $this->getJson('/api/tax-cases/export?'.http_build_query($filters));
        if (! $expected) {
            $response->assertNotFound()->assertJsonPath('success', false);
        } else {
            $exported = $response->assertOk()->json('numbers');
            sort($exported);
            $this->assertSame($expected, $exported);
        }
    }

    public function test_each_filter_and_intersections_match_independent_expected_identities(): void
    {
        $a = $this->make(['case_number' => 'Target_%!mixed', 'current_stage' => 4]);
        $b = $this->make(['entity_id' => $this->b->id, 'case_type' => 'VAT', 'is_completed' => true, 'current_stage' => 16]);
        $c = $this->make(['current_stage' => 3]);
        $this->history($a, 4, 'draft');
        $this->history($a, 4, 'submitted'); // same timestamp: highest ID wins
        $this->history($a, 2, 'completed'); // workbook has two legitimate rows
        $this->history($b, 7, 'completed');
        $this->history($b, 16, 'approved');
        $this->history($b, 15, 'completed'); // retired history does not become main stage
        $this->parity([], [$a->case_number, $b->case_number, $c->case_number]);
        foreach ([['case_number' => 'TARGET_%!'], ['stage_status' => 'submitted'], ['period_code' => $a->period->period_code],
            ['period_id' => $a->period_id], ['current_stage' => 4]] as $filter) {
            $this->parity($filter, [$a->case_number]);
        }
        foreach ([['case_type' => 'VAT'], ['case_status' => 'closed'], ['entity_id' => $this->b->id],
            ['search' => 'bEtA'], ['stage_status' => 'approved'], ['current_stage' => 7]] as $filter) {
            $this->parity($filter, [$b->case_number]);
        }
        $this->parity(['stage_status' => 'draft'], [$c->case_number]);
        $this->parity(['case_status' => 'open'], [$a->case_number, $c->case_number]);
        $this->parity(['fiscal_year' => 2025], []); // period year is deliberately different
        $this->parity(['fiscal_year' => 2026], [$a->case_number, $b->case_number, $c->case_number]);
        $this->parity(['search' => 'alpha', 'case_number' => 'mixed', 'case_type' => 'CIT', 'case_status' => 'open',
            'current_stage' => 4, 'stage_status' => 'submitted', 'period_code' => $a->period->period_code,
            'fiscal_year' => 2026, 'entity_id' => $this->a->id], [$a->case_number]);
        $this->parity(['case_number' => 'Target', 'entity_id' => $this->b->id], []);
        $this->parity(['search' => 'absent'], []);
        $this->parity(['search' => '', 'case_type' => '', 'period_id' => '', 'period_code' => ''], [$a->case_number, $b->case_number, $c->case_number]);
    }

    public function test_scope_intersection_guest_and_existing_role_contract(): void
    {
        $a = $this->make();
        $this->make(['entity_id' => $this->b->id]);
        $this->actingAs($this->affiliate);
        $this->parity([], [$a->case_number]);
        $this->parity(['entity_id' => $this->b->id, 'search' => 'Beta'], []);
        $options = $this->getJson('/api/tax-cases')->json('data.filter_options.periods');
        $this->assertSame([$a->period->period_code], $options);
        $this->affiliate->entity_id = null;
        $this->affiliate->unsetRelation('entity');
        $this->parity([], []);
        auth()->forgetGuards();
        $this->getJson('/api/tax-cases')->assertUnauthorized();
        $this->getJson('/api/tax-cases/export')->assertUnauthorized();
        // Existing endpoints allow authenticated STAFF; no new role restriction is invented.
    }

    public function test_invalid_filters_are_rejected_equally(): void
    {
        foreach ([['case_number' => ['array']], ['case_type' => 'BAD'], ['entity_id' => -1], ['case_status' => 'BAD'],
            ['current_stage' => 13], ['current_stage' => 16], ['stage_status' => 'BAD'], ['fiscal_year' => 'bad'],
            ['period_id' => 'P1'], ['period_code' => 'P1', 'period_id' => 1], ['search' => ['bad']]] as $filter) {
            foreach (['/api/tax-cases?', '/api/tax-cases/export?'] as $path) {
                $this->getJson($path.http_build_query($filter))->assertUnprocessable();
            }
        }
    }

    public function test_latest_status_and_missing_period_and_branch_evidence(): void
    {
        $cases = [];
        foreach (['draft', 'submitted', 'approved', 'rejected', 'completed'] as $status) {
            $case = $this->make(['current_stage' => 4]);
            $this->history($case, 4, $status, '2026-02-01');
            $this->history($case, 4, $status === 'draft' ? 'submitted' : 'draft', '2026-01-01');
            $cases[$status] = $case->case_number;
        }
        $missing = $this->make(['period_id' => null, 'current_stage' => 16]);
        foreach ($cases as $status => $number) {
            $this->parity(['stage_status' => $status, 'current_stage' => 4], [$number]);
        }
        $this->parity(['stage_status' => 'draft'], [$cases['draft'], $missing->case_number]);
        $this->parity(['period_code' => 'not-present'], []);
        $this->parity(['period_id' => 999999], []);
        $this->parity(['status_id' => $this->status->id, 'case_number' => $missing->case_number], [$missing->case_number]);
        $this->getJson('/api/tax-cases?case_number='.$missing->case_number)
            ->assertJsonPath('data.data.0.display_stage', null)->assertJsonPath('data.data.0.list_stage_status', 'draft');
    }

    public function test_pagination_and_query_growth_remain_bounded(): void
    {
        $case = $this->make(['current_stage' => 16]);
        $this->history($case, 4, 'completed');
        $this->history($case, 16, 'submitted');
        $this->holding->load('entity');
        $measure = function (string $path): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($path)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $filter = '?current_stage=4&stage_status=submitted';
        $listBefore = $measure('/api/tax-cases'.$filter);
        $exportBefore = $measure('/api/tax-cases/export'.$filter);
        $numbers = [$case->case_number];
        for ($i = 0; $i < 20; $i++) {
            $entity = Entity::create(['code' => "EXTRA-$i", 'name' => "Extra $i", 'entity_type' => 'AFFILIATE', 'tax_id' => "EXTRA-$i"]);
            $extra = $this->make(['current_stage' => 16, 'entity_id' => $entity->id]);
            $this->history($extra, 2, 'completed');
            $this->history($extra, 3, 'completed');
            $this->history($extra, 4, 'completed');
            $this->history($extra, 16, 'submitted');
            $numbers[] = $extra->case_number;
        }
        $listAfter = $measure('/api/tax-cases'.$filter);
        $exportAfter = $measure('/api/tax-cases/export'.$filter);
        $this->assertLessThanOrEqual($listBefore, $listAfter);
        $this->assertLessThanOrEqual($exportBefore, $exportAfter);
        fwrite(STDOUT, "\nFilter query counts (1 -> 21 cases): list $listBefore -> $listAfter; export $exportBefore -> $exportAfter\n");
        $filters = app(\App\Services\TaxCaseListFilters::class);
        $query = $filters->apply($filters->authorized($this->holding), ['current_stage' => 4, 'stage_status' => 'submitted']);
        $plan = implode("\n", array_column(DB::select('EXPLAIN QUERY PLAN '.$query->toSql(), $query->getBindings()), 'detail'));
        $this->assertStringContainsString('workflow_histories_tax_case_id_stage_id_created_at_index', $plan);
        fwrite(STDOUT, "SQLite plan uses workflow_histories_tax_case_id_stage_id_created_at_index.\n");
        $this->getJson('/api/tax-cases'.$filter)->assertJsonCount(15, 'data.data')->assertJsonPath('data.total', 21);
        $this->parity(['current_stage' => 4, 'stage_status' => 'submitted'], $numbers);
        $this->parity(['case_number' => $case->case_number, 'period_id' => $case->period_id], [$case->case_number]);
    }

    public function test_workbook_contract_and_history_expansion_are_preserved(): void
    {
        $case = $this->make(['current_stage' => 2]);
        $this->history($case, 1, 'submitted');
        $this->history($case, 2, 'draft');
        $this->history($case, 2, 'submitted');
        $case->load(['entity', 'fiscalYear', 'currency', 'workflowHistories', 'kianSubmission', 'sp2Record']);
        $sheets = (new TaxCasesExport(collect([$case])))->sheets();
        $this->assertCount(1, $sheets);
        $this->assertSame('SAI', $sheets[0]->title());
        $this->assertSame(['FY', 'Ref #', 'Input Date', 'Audit Status', 'Kian', 'Amount (Orig. Curr)', 'Amount (USD)',
            'Tax Appeal #', 'Tax Appeal', 'Next Action', 'Next Action Due Date', 'Status Comments'], $sheets[0]->headings());
        $rows = $sheets[0]->collection();
        $this->assertCount(2, $rows);
        $this->assertSame([$case->case_number], $rows->pluck('Ref #')->unique()->values()->all());
        $this->assertSame(1000.0, $rows[0]['Amount (Orig. Curr)']);
        $this->assertSame(1000.0, $rows[0]['Amount (USD)']);
        $this->assertSame('SPT Filing', $rows[0]['Audit Status']);
        $this->assertSame('SP2 Audit Notice', $rows[1]['Audit Status']);
        $this->assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $rows[0]['Input Date']);
        $other = $this->make(['entity_id' => $this->b->id]);
        $other->load(['entity', 'fiscalYear', 'currency', 'workflowHistories', 'kianSubmission']);
        $both = (new TaxCasesExport(collect([$case, $other])))->sheets();
        $this->assertSame(['SAI', 'BBB'], array_map(fn ($sheet) => $sheet->title(), $both));
        $this->assertCount(1, $both[1]->collection());
    }
}
