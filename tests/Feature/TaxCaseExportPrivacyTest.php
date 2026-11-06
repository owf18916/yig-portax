<?php

namespace Tests\Feature;

use App\Exports\TaxCasesExport;
use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;
use Monolog\Handler\TestHandler;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class TaxCaseExportPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $logs;

    private TaxCase $case;

    protected function beforeRefreshingDatabase(): void
    {
        // Fail closed before migrations or fixture writes, including direct PHPUnit use.
        $this->assertSame('testing', app()->environment());
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);
        $root = str_replace('\\', '/', storage_path());
        $this->assertStringStartsWith(str_replace('\\', '/', sys_get_temp_dir()).'/portax_auth_sec01_', $root);
        foreach (['cache.default' => 'array', 'session.driver' => 'array', 'mail.default' => 'array',
            'queue.default' => 'sync', 'logging.default' => 'null', 'filesystems.default' => 'local'] as $key => $value) {
            $this->assertSame($value, config($key), $key);
        }
        foreach (['local', 'public', 'nas'] as $disk) {
            $this->assertSame('local', config("filesystems.disks.$disk.driver"));
            $this->assertStringStartsWith($root.'/', str_replace('\\', '/', config("filesystems.disks.$disk.root")));
        }
        $this->assertStringStartsWith($root.'/', str_replace('\\', '/', config('excel.temporary_files.local_path')));
        $this->assertNull(config('excel.temporary_files.remote_disk'));
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        $this->logs = new TestHandler(\Monolog\Level::Debug);
        // Capture application messages and context in memory, at every severity.
        Log::swap(new Logger(new \Monolog\Logger('sec01', [$this->logs])));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $entity = Entity::create(['code' => 'SEC01-A', 'name' => 'Fictional SEC01', 'entity_type' => 'AFFILIATE', 'tax_id' => 'SEC01']);
        $role = Role::create(['code' => 'STAFF', 'name' => 'Staff']);
        $user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Fictional currency', 'symbol' => 'Rp', 'exchange_rate' => 2]);
        $status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        $this->case = TaxCase::create([
            'user_id' => $user->id, 'entity_id' => $entity->id, 'fiscal_year_id' => $year->id,
            'currency_id' => $currency->id, 'case_status_id' => $status->id,
            'case_number' => 'SEC01-FICTIONAL-REF', 'case_type' => 'CIT', 'current_stage' => 1,
            'reported_amount' => 76543.22, 'disputed_amount' => 76543.22, 'status_comment' => 'SEC01 fictional private comment',
            'next_action' => 'SEC01 fictional next action', 'next_action_due_date' => '2026-11-20',
        ]);
        $this->case->forceFill(['created_at' => '2026-11-06 01:02:03'])->save();
        $this->actingAs($user);
        $this->logs->clear();
    }

    public static function failures(): array
    {
        return [
            'exception debug off' => [false, \RuntimeException::class],
            'exception debug on' => [true, \RuntimeException::class],
            'error debug off' => [false, \Error::class],
            'error debug on' => [true, \Error::class],
        ];
    }

    #[DataProvider('failures')]
    public function test_failed_export_discloses_nothing_and_has_no_side_effects(bool $debug, string $exception): void
    {
        config(['app.debug' => $debug]);
        $sentinels = ['SEC01-FICTIONAL-REF', '76543.22', 'SEC01 fictional private comment',
            '/fictional/sec01/private.xlsx', 'SQLSTATE[SEC01] connection=fictional SELECT private_value'];
        $payload = implode(' | ', $sentinels);
        Excel::shouldReceive('download')->once()->andReturnUsing(function (TaxCasesExport $export) use ($exception, $payload) {
            // Exercise actual sheet/row creation before the synthetic writer failure.
            $export->sheets()[0]->collection();
            throw new $exception($payload);
        });
        $before = $this->files();
        $response = $this->getJson('/api/tax-cases/export')->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Failed to export tax cases.']);
        foreach (array_merge($sentinels, [$exception, 'trace', 'exception']) as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
            $this->assertStringNotContainsString($private, serialize($this->logs->getRecords()));
        }
        $this->assertSame([], $this->logs->getRecords());
        $this->assertSame($before, $this->files());
        $this->assertNoExternalEffects();
    }

    public function test_success_preserves_private_cells_and_real_download_response_without_logging(): void
    {
        config(['app.debug' => true]);
        $historyCase = $this->case->replicate()->fill(['case_number' => 'SEC01-HISTORY-REF']);
        $historyCase->save();
        foreach ([1, 2, 2, 3] as $stage) {
            $historyCase->workflowHistories()->create(['stage_id' => $stage, 'status' => 'submitted',
                'action' => 'submitted', 'user_id' => $this->case->user_id]);
        }
        $loaded = TaxCase::with(['entity', 'fiscalYear', 'currency', 'workflowHistories', 'kianSubmission', 'sp2Record', 'sphpRecord'])->get();
        DB::enableQueryLog();
        DB::flushQueryLog();
        (new TaxCasesExport($loaded->take(1)))->sheets()[0]->collection();
        $this->assertSame([], $this->logs->getRecords(), 'One case/one row produces no export logs.');
        $sheets = (new TaxCasesExport($loaded))->sheets();
        $rows = $sheets[0]->collection();
        $this->assertSame([], $this->logs->getRecords(), 'Two cases/four rows produce no export logs.');
        $this->assertCount(0, DB::getQueryLog(), 'No per-row queries when using the controller eager loads.');
        DB::disableQueryLog();
        $this->assertCount(1, $sheets);
        $this->assertSame('SEC01-A', $sheets[0]->title());
        $this->assertSame(['FY', 'Ref #', 'Input Date', 'Audit Status', 'Kian', 'Amount (Orig. Curr)', 'Amount (USD)',
            'Tax Appeal #', 'Tax Appeal', 'Next Action', 'Next Action Due Date', 'Status Comments'], $sheets[0]->headings());
        $this->assertCount(4, $rows);
        $this->assertSame(['SEC01-FICTIONAL-REF', 'SEC01-HISTORY-REF'], $rows->pluck('Ref #')->unique()->values()->all());
        $this->assertSame(['SPT Filing', 'SP2 Audit Notice', 'SPHP Audit Findings'], $rows->slice(1)->pluck('Audit Status')->all());
        $this->assertSame([2026, 'SEC01-FICTIONAL-REF', '06/11/2026', '', '', 76543.22, 38271.61, '', '',
            'SEC01 fictional next action', '20-Nov-26', 'SEC01 fictional private comment'], array_values($rows[0]));
        $this->assertSame('SEC01 fictional private comment', $rows[1]['Status Comments']);

        // Actual installed writer and BinaryFileResponse, no browser or saved download claim.
        $response = $this->getJson('/api/tax-cases/export')->assertOk();
        $binary = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $binary);
        $this->assertMatchesRegularExpression('/attachment; filename=tax-cases-export-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx/', $binary->headers->get('Content-Disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $binary->headers->get('Content-Type'));
        $path = $binary->getFile()->getPathname();
        $this->assertStringStartsWith(str_replace('\\', '/', realpath(storage_path())).'/', str_replace('\\', '/', realpath($path)));
        $book = IOFactory::load($path);
        $this->assertSame(['SEC01-A'], $book->getSheetNames());
        $sheet = $book->getSheet(0);
        $this->assertSame('SEC01-FICTIONAL-REF', $sheet->getCell('B2')->getValue());
        $this->assertSame(76543.22, $sheet->getCell('F2')->getValue());
        $this->assertSame(38271.61, $sheet->getCell('G2')->getValue());
        $this->assertSame('SEC01 fictional private comment', $sheet->getCell('L2')->getValue());
        $this->assertSame(5, $sheet->getHighestRow());
        $book->disconnectWorksheets();
        $this->assertSame([], $this->logs->getRecords());
        $this->assertNoExternalEffects();
    }

    private function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(storage_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function assertNoExternalEffects(): void
    {
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
