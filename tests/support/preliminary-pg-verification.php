<?php

// Loaded exclusively after the disposable runner's independent identity/config gates.
use App\Models\BankTransferRequest;
use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Document;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\PreliminaryRefundRequest;
use App\Models\RefundProcess;
use App\Models\Role;
use App\Models\StatusHistory;
use App\Models\TaxCase;
use App\Models\User;
use App\Models\WorkflowHistory;
use App\Services\PreliminaryRefundLifecycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function pgCheck(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }
}

function pgPdf(string $content = 'evidence'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\n".$content."\n%%EOF");
}

function pgFixture(string $tag): array
{
    $entity = Entity::create(['code' => $tag, 'name' => $tag, 'entity_type' => 'AFFILIATE', 'tax_id' => $tag]);
    $role = Role::firstOrCreate(['code' => 'STAFF'], ['name' => 'Staff', 'permissions' => ['submit_cases'], 'is_active' => true]);
    $user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
    $year = FiscalYear::firstOrCreate(['year' => 2026], ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $currency = Currency::firstOrCreate(['code' => 'IDR'], ['name' => 'Rupiah', 'symbol' => 'Rp']);
    $status = CaseStatus::firstOrCreate(['code' => 'OPEN'], ['name' => 'Open']);
    CaseStatus::firstOrCreate(['code' => 'CLOSED'], ['name' => 'Closed']);
    $case = TaxCase::create(['entity_id' => $entity->id, 'user_id' => $user->id, 'fiscal_year_id' => $year->id,
        'case_number' => $tag, 'case_type' => 'VAT', 'case_status_id' => $status->id, 'currency_id' => $currency->id,
        'spt_position_type' => 'LB', 'spt_type' => 'Pengembalian Pendahuluan', 'reported_amount' => '1000.00',
        'disputed_amount' => '1000.00', 'current_stage' => 1]);
    WorkflowHistory::create(['tax_case_id' => $case->id, 'stage_id' => 1, 'action' => 'submitted', 'status' => 'submitted', 'user_id' => $user->id]);
    auth()->login($user);

    return [$case, $user];
}

function pgCommand(array $spec): mixed
{
    $service = app(PreliminaryRefundLifecycle::class);

    return match ($spec['op']) {
        'submit' => $service->submit($spec['case'], ['submission_date' => $spec['date'] ?? '2026-10-30', 'requested_amount' => $spec['amount'] ?? '900.12'], pgPdf($spec['content'] ?? 'evidence')),
        'approve', 'reject' => $service->decide($spec['case'], $spec['request'], $spec['op'], $spec['amount'] ?? '800.12', $spec['op'] === 'reject' ? 'External rejection reason' : null, '2026-10-30', pgPdf($spec['content'] ?? 'evidence')),
        'receipt' => $service->payment($spec['case'], $spec['refund'], 4, ['receipt_number' => $spec['receipt'] ?? 'RECEIPT', 'received_date' => '2026-10-30', 'received_amount' => $spec['amount'] ?? '800.12']),
    };
}

$mode = $argv[2];
$root = storage_path('framework/testing/phase3c2_'.getenv('PORTAX_TEST_PG_DATABASE'));
if (! is_dir($root)) {
    mkdir($root, 0700, true);
}
config(['filesystems.disks.local.root' => $root.'/private']);
Storage::forgetDisk('local');
DB::statement("SET lock_timeout = '8s'");
DB::statement("SET statement_timeout = '20s'");

if ($mode === 'preliminary-worker') {
    $spec = json_decode(file_get_contents($argv[3]), true, flags: JSON_THROW_ON_ERROR);
    auth()->login(User::findOrFail($spec['user']));
    DB::select("SELECT set_config('application_name', ?, false)", [$spec['name']]);
    $held = false;
    if ($spec['leader']) {
        DB::listen(function ($query) use (&$held, $spec) {
            if ($held || ! str_contains(strtolower($query->sql), 'for update') || ! str_contains($query->sql, 'tax_cases')) {
                return;
            }
            $held = true;
            file_put_contents($spec['barrier'], 'locked');
            $deadline = microtime(true) + 12;
            do {
                // PostgreSQL caches activity snapshots within the leader's transaction.
                DB::select('SELECT pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$spec['peer']])->n;
                if ($waiting > 0) {
                    file_put_contents($spec['barrier'].'.contended', 'observed');

                    return;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            throw new RuntimeException('Peer never demonstrably contended on the case lock.');
        });
    }
    try {
        $result = pgCommand($spec);
        $outcome = ['outcome' => 'ok', 'id' => $result->id];
    } catch (Illuminate\Validation\ValidationException|Illuminate\Auth\Access\AuthorizationException|Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        $outcome = ['outcome' => 'refused', 'class' => get_class($e), 'message' => $e->getMessage()];
    }
    pgCheck(DB::transactionLevel() === 0, 'Worker leaked a transaction.');
    file_put_contents($spec['result'], json_encode($outcome, JSON_THROW_ON_ERROR));
    exit(0);
}

function pgCatalog(): array
{
    return [
        'columns' => DB::select("SELECT column_name,is_nullable,column_default,data_type FROM information_schema.columns WHERE table_name='preliminary_refund_requests' ORDER BY ordinal_position"),
        'constraints' => DB::select("SELECT conname,contype,convalidated,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid IN ('preliminary_refund_requests'::regclass,'documents'::regclass,'kian_submissions'::regclass) ORDER BY conname"),
        'indexes' => DB::select("SELECT indexrelid::regclass::text AS name,indisvalid,indisready,pg_get_indexdef(indexrelid) AS definition FROM pg_index WHERE indrelid IN ('preliminary_refund_requests'::regclass,'documents'::regclass,'kian_submissions'::regclass) ORDER BY name"),
        'ledger' => DB::table('migrations')->orderBy('id')->get()->toArray(),
    ];
}

function pgSchemaChecks(): void
{
    $catalog = pgCatalog();
    $columns = collect($catalog['columns'])->keyBy('column_name');
    pgCheck(str_contains($columns['approval_status']->column_default, 'SUBMITTED'), 'Wrong status default.');
    foreach (['submission_document_id', 'decision_document_id', 'decision_date'] as $name) {
        pgCheck($columns[$name]->is_nullable === 'YES', 'New history columns must be nullable.');
    }
    $defs = collect($catalog['constraints'])->keyBy('conname');
    foreach (['submission_document_id', 'decision_document_id'] as $name) {
        pgCheck(str_contains($defs['preliminary_refund_requests_'.$name.'_foreign']->definition, 'REFERENCES documents(id) ON DELETE RESTRICT'), 'Wrong evidence FK.');
    }
    pgCheck(str_contains($defs['preliminary_refund_requests_tax_case_id_unique']->definition, 'UNIQUE (tax_case_id)'), 'Missing case uniqueness.');
    $kianIndex = collect($catalog['indexes'])->firstWhere('name', 'kian_submissions_tax_case_stage_unique');
    pgCheck($kianIndex && str_contains($kianIndex->definition, 'UNIQUE INDEX') && str_contains($kianIndex->definition, '(tax_case_id, stage_id)'), 'KIAN pair uniqueness lost.');
    foreach ($catalog['constraints'] as $constraint) {
        pgCheck((bool) $constraint->convalidated, 'Invalid constraint.');
    }
    foreach ($catalog['indexes'] as $index) {
        pgCheck($index->indisvalid && $index->indisready, 'Invalid index.');
    }
    $duplicates = DB::select("SELECT indrelid,indkey,indclass,indcollation,indoption,indexprs::text,indpred::text,count(*) FROM pg_index WHERE indrelid IN ('preliminary_refund_requests'::regclass,'documents'::regclass,'kian_submissions'::regclass) GROUP BY indrelid,indkey,indclass,indcollation,indoption,indexprs::text,indpred::text HAVING count(*) > 1");
    // Historical documents has both uploaded_by index and FK index under some engines; report actual PG duplicates.
    pgCheck($duplicates === [], 'Duplicate indexes found.');
    file_put_contents(storage_path('framework/testing/phase3c2_'.getenv('PORTAX_TEST_PG_DATABASE').'/catalog.json'), json_encode($catalog, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

if ($mode === 'preliminary-schema') {
    $upgrade = ($argv[3] ?? '') === 'upgrade';
    $snapshots = [];
    if ($upgrade) {
        pgCheck(DB::selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema='public'")->n === 0, 'Upgrade requires a genuinely empty new database.');
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_10_30_000001'));
        pgCheck(Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true]) === 0, 'Historical migration failed.');
        pgCheck(DB::table('migrations')->orderByDesc('id')->value('migration') === '2026_10_29_000002_reconcile_kian_case_stage_uniqueness', 'Wrong historical boundary.');
        foreach (['PENDING', 'APPROVED', 'REJECTED'] as $i => $status) {
            [$case, $user] = pgFixture('UP'.$i);
            $request = PreliminaryRefundRequest::create(['tax_case_id' => $case->id, 'approval_status' => $status, 'requested_amount' => '900.12', 'approved_amount' => $status === 'APPROVED' ? '800.12' : '0.00', 'submitted_by' => $user->id, 'submitted_at' => now()]);
            $path = 'historical/'.$request->id.'.pdf';
            Storage::disk('local')->put($path, "%PDF-1.4\nhistorical\n%%EOF");
            $doc = Document::create(['tax_case_id' => $case->id, 'documentable_type' => PreliminaryRefundRequest::class, 'documentable_id' => $request->id, 'document_type' => 'historical', 'stage_code' => '0', 'original_filename' => 'old.pdf', 'file_path' => $path, 'hash' => Storage::disk('local')->checksum($path, ['checksum_algo' => 'sha256']), 'uploaded_at' => now(), 'uploaded_by' => $user->id]);
            if ($i === 1) {
                RefundProcess::create(['tax_case_id' => $case->id, 'stage_id' => 0, 'stage_source' => 'PRELIMINARY', 'refund_amount' => '800.12', 'refund_method' => 'bank_transfer', 'refund_number' => 'HISTORICAL', 'refund_status' => 'pending', 'status' => 'approved', 'sequence_number' => 1, 'triggered_by_decision_type' => PreliminaryRefundRequest::class, 'triggered_by_decision_id' => $request->id]);
            }
            if ($i === 2) {
                $request->delete();
                $doc->delete();
            }
        }
        // POSTPONED was not legal before 000001. Seed it only at its genuine forward boundary.
        foreach (['preliminary_refund_requests', 'documents', 'refund_processes'] as $table) {
            $snapshots[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        pgCheck(Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_30_000001_allow_postponed_preliminary_tax_office_decision.php', '--force' => true]) === 0, 'First forward migration failed.');
        [$case] = pgFixture('UPPOST');
        $request = PreliminaryRefundRequest::create(['tax_case_id' => $case->id, 'approval_status' => 'POSTPONED', 'requested_amount' => '700.13', 'status_comment' => 'Historical postponed']);
        $snapshots['preliminary_refund_requests'][] = (array) DB::table('preliminary_refund_requests')->where('id', $request->id)->first();
    }
    pgCheck(Artisan::call('migrate', ['--force' => true]) === 0, 'Forward migration failed.');
    foreach ($snapshots as $table => $rows) {
        foreach ($rows as $before) {
            $after = (array) DB::table($table)->where('id', $before['id'])->first();
            pgCheck(array_intersect_key($after, $before) === $before, 'Historical row changed: '.$table);
        }
    }
    foreach (Document::withTrashed()->get() as $document) {
        pgCheck(hash('sha256', Storage::disk('local')->get($document->file_path)) === $document->hash, 'Historical document changed.');
    }
    pgSchemaChecks();
    $before = json_encode(pgCatalog());
    $dataBefore = DB::selectOne("SELECT md5(coalesce(json_agg(t ORDER BY id)::text,'')) AS hash FROM preliminary_refund_requests t")->hash;
    pgCheck(Artisan::call('migrate', ['--force' => true]) === 0, 'No-op migration failed.');
    pgCheck(json_encode(pgCatalog()) === $before && DB::selectOne("SELECT md5(coalesce(json_agg(t ORDER BY id)::text,'')) AS hash FROM preliminary_refund_requests t")->hash === $dataBefore, 'No-op changed schema/data.');
    [$case] = pgFixture('CHECK');
    foreach (['PENDING', 'POSTPONED', 'SUBMITTED', 'APPROVED', 'REJECTED'] as $status) {
        DB::transaction(function () use ($case, $status) {
            $id = DB::table('preliminary_refund_requests')->insertGetId(['tax_case_id' => $case->id, 'requested_amount' => '1.01', 'approval_status' => $status]);
            DB::table('preliminary_refund_requests')->where('id', $id)->delete();
        });
    }
    try {
        DB::transaction(fn () => DB::table('preliminary_refund_requests')->insert(['tax_case_id' => $case->id, 'requested_amount' => '1.01', 'approval_status' => 'DRAFT']));
        throw new RuntimeException('DRAFT unexpectedly allowed.');
    } catch (Illuminate\Database\QueryException $e) {
        pgCheck($e->getCode() === '23514', 'Unexpected invalid-status failure.');
    }
    $id = DB::table('preliminary_refund_requests')->insertGetId(['tax_case_id' => $case->id, 'requested_amount' => '1.01', 'deleted_at' => now()]);
    try {
        DB::transaction(fn () => DB::table('preliminary_refund_requests')->insert(['tax_case_id' => $case->id, 'requested_amount' => '1.01']));
        throw new RuntimeException('Soft-deleted request bypassed uniqueness.');
    } catch (Illuminate\Database\QueryException $e) {
        pgCheck($e->getCode() === '23505', 'Unexpected duplicate failure.');
    }
    pgCheck(DB::table('preliminary_refund_requests')->where('id', $id)->value('approval_status') === 'SUBMITTED', 'Default not applied.');
    $document = Document::create(['tax_case_id' => $case->id, 'documentable_type' => PreliminaryRefundRequest::class, 'documentable_id' => $id, 'document_type' => 'historical', 'stage_code' => '0', 'original_filename' => 'fk.pdf', 'file_path' => 'fk-fixture.pdf', 'uploaded_at' => now()]);
    DB::table('preliminary_refund_requests')->where('id', $id)->update(['submission_document_id' => $document->id, 'decision_document_id' => $document->id]);
    foreach (['submission_document_id', 'decision_document_id'] as $column) {
        try {
            DB::transaction(fn () => DB::table('preliminary_refund_requests')->where('id', $id)->update([$column => 9223372036854775807]));
            throw new RuntimeException('Dangling evidence FK accepted.');
        } catch (Illuminate\Database\QueryException $e) {
            pgCheck($e->getCode() === '23503', 'Unexpected evidence FK failure.');
        }
    }
    try {
        DB::transaction(fn () => DB::table('documents')->where('id', $document->id)->delete());
        throw new RuntimeException('Referenced evidence deletion accepted.');
    } catch (Illuminate\Database\QueryException $e) {
        pgCheck($e->getCode() === '23503', 'Unexpected restricted deletion failure.');
    }
    echo 'PASS: '.($upgrade ? 'actual historical upgrade (POSTPONED seeded only after 000001)' : 'fresh installation').' schema, history/files, five statuses, invalid status, soft-delete uniqueness, no-op, catalog validity.'.PHP_EOL;
    exit(0);
}

// New, separate database only: do not re-run fixture creation over an existing race database.
pgCheck(DB::selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema='public'")->n === 0, 'Races require a new empty database.');
pgCheck(Artisan::call('migrate', ['--force' => true]) === 0, 'Race migration failed.');
$races = [
    'submission-identical' => ['submit', 'submit'],
    'submission-conflict' => ['submit', 'submit'],
    'approval-identical' => ['approve', 'approve'],
    'approval-conflict' => ['approve', 'approve'],
    'approval-rejection' => ['approve', 'reject'],
    'rejection-approval' => ['reject', 'approve'],
    'receipt-identical' => ['receipt', 'receipt'],
    'receipt-conflict' => ['receipt', 'receipt'],
    'child-crosscase' => ['approve', 'approve'],
    'receipt-crosscase' => ['receipt', 'receipt'],
];
foreach ($races as $label => [$left, $right]) {
    [$case, $user] = pgFixture('R'.Entity::count());
    $base = ['case' => $case->id, 'user' => $user->id];
    if ($left !== 'submit') {
        $request = pgCommand($base + ['op' => 'submit']);
        $base['request'] = $request->id;
    }
    if ($left === 'receipt') {
        pgCommand($base + ['op' => 'approve']);
        $refund = RefundProcess::where('tax_case_id', $case->id)->sole();
        $base['refund'] = $refund->id;
        $service = app(PreliminaryRefundLifecycle::class);
        $service->payment($case->id, $refund->id, 2, ['request_number' => 'REQ', 'request_date' => '2026-10-30', 'transfer_date' => '2026-10-30']);
        $service->payment($case->id, $refund->id, 3, ['instruction_number' => 'INS', 'instruction_issue_date' => '2026-10-30', 'instruction_received_date' => '2026-10-30']);
        foreach (['0.00', '400.00'] as $amount) {
            try {
                pgCommand($base + ['op' => 'receipt', 'amount' => $amount]);
                throw new RuntimeException('Partial/zero receipt accepted.');
            } catch (Illuminate\Validation\ValidationException) {
                pgCheck(! $case->fresh()->is_completed && StatusHistory::where('tax_case_id', $case->id)->count() === 0, 'Early closure.');
            }
        }
    }
    $barrier = $root.'/'.$label;
    if (str_contains($label, 'crosscase')) {
        [$foreign, $foreignUser] = pgFixture('F'.Entity::count());
        $foreignRequest = pgCommand(['case' => $foreign->id, 'user' => $foreignUser->id, 'op' => 'submit']);
        if ($left === 'receipt') {
            pgCommand(['case' => $foreign->id, 'request' => $foreignRequest->id, 'op' => 'approve']);
            $foreignRefund = RefundProcess::where('tax_case_id', $foreign->id)->sole();
        }
        $foreignBefore = json_encode(DB::table('preliminary_refund_requests')->where('id', $foreignRequest->id)->first());
        auth()->login($user);
    }
    $processes = [];
    foreach (['A' => $left, 'B' => $right] as $side => $op) {
        $spec = $base + ['op' => $op, 'leader' => $side === 'A', 'barrier' => $barrier, 'name' => $label.$side, 'peer' => $label.'B', 'result' => $barrier.'.'.$side.'.json'];
        if ($side === 'B' && str_contains($label, 'crosscase')) {
            if ($op === 'receipt') {
                $spec['refund'] = $foreignRefund->id;
            } else {
                $spec['request'] = $foreignRequest->id;
            }
        }
        if ($side === 'B' && str_contains($label, 'conflict')) {
            if ($op === 'receipt') {
                $spec['receipt'] = 'CONFLICT';
            } else {
                $spec += ['amount' => '700.11', 'content' => 'conflicting', 'date' => '2026-10-29'];
            }
        }
        file_put_contents($barrier.'.'.$side.'.input.json', json_encode($spec, JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, base_path('tests/run-kian-disposable.php'), 'pgsql', 'preliminary-worker', $barrier.'.'.$side.'.input.json'], [0 => ['pipe', 'r'], 1 => ['file', $barrier.'.'.$side.'.log', 'w'], 2 => ['file', $barrier.'.'.$side.'.err', 'w']], $pipes, base_path());
        pgCheck(is_resource($process), 'Worker launch failed.');
        fclose($pipes[0]);
        $processes[$side] = $process;
        if ($side === 'A') {
            $deadline = microtime(true) + 15;
            while (! is_file($barrier) && microtime(true) < $deadline && proc_get_status($process)['running']) {
                usleep(20000);
            }
            pgCheck(is_file($barrier), 'Leader failed to acquire lock: '.$barrier);
        }
    }
    $deadline = microtime(true) + 30;
    foreach ($processes as $side => $process) {
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(20000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
            throw new RuntimeException('Bounded worker deadline exceeded.');
        }
        proc_close($process);
        pgCheck(is_file($barrier.'.'.$side.'.json'), 'Worker failed: '.$barrier.'.'.$side.'.err');
        $outcomes[$side] = json_decode(file_get_contents($barrier.'.'.$side.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }
    pgCheck(is_file($barrier.'.contended'), 'No physical lock contention observed.');
    pgCheck($outcomes['A']['outcome'] === 'ok', 'Leader did not succeed.');
    $identical = str_contains($label, 'identical');
    pgCheck($outcomes['B']['outcome'] === ($identical ? 'ok' : 'refused'), 'Wrong follower outcome.');
    $request = PreliminaryRefundRequest::withTrashed()->where('tax_case_id', $case->id)->sole();
    $refunds = RefundProcess::withTrashed()->where('tax_case_id', $case->id)->get();
    $documents = Document::withTrashed()->where('tax_case_id', $case->id)->get();
    $expectedStatus = $left === 'submit' ? 'SUBMITTED' : ($left === 'reject' ? 'REJECTED' : 'APPROVED');
    pgCheck($request->approval_status === $expectedStatus && $request->requested_amount === '900.12', 'Request overwritten.');
    pgCheck($refunds->count() === (in_array($left, ['approve', 'receipt'], true) ? 1 : 0), 'Refund invariant failed.');
    pgCheck($documents->count() === ($left === 'submit' ? 1 : 2), 'Document invariant failed.');
    $files = Storage::disk('local')->allFiles('tax_cases/'.$case->id);
    pgCheck(count($files) === $documents->count(), 'Failed/replayed upload residue found.');
    foreach ($documents as $document) {
        pgCheck((int) $document->documentable_id === $request->id && $document->documentable_type === PreliminaryRefundRequest::class, 'Wrong document owner.');
        pgCheck(hash('sha256', Storage::disk('local')->get($document->file_path)) === $document->hash && $document->hash === hash('sha256', "%PDF-1.4\nevidence\n%%EOF"), 'Evidence content overwritten.');
        pgCheck(! array_key_exists('file_path', $document->toArray()), 'Private path leaked.');
    }
    $history = StatusHistory::where('tax_case_id', $case->id)->count();
    $transfers = BankTransferRequest::withTrashed()->whereIn('refund_process_id', $refunds->pluck('id'))->get();
    pgCheck($history === ($left === 'receipt' ? 1 : 0) && (bool) $case->fresh()->is_completed === ($left === 'receipt'), 'Closure/history invariant failed.');
    if ($left === 'receipt') {
        pgCheck($transfers->count() === 1 && $transfers->first()->received_amount === '800.12' && $case->fresh()->status->code === 'CLOSED', 'Receipt invariant failed.');
    }
    $held = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name IN (?, ?) AND (xact_start IS NOT NULL OR wait_event_type='Lock')", [$label.'A', $label.'B'])->n;
    pgCheck((int) $held === 0, 'Worker transaction/lock remains.');
    if (str_contains($label, 'crosscase')) {
        pgCheck(json_encode(DB::table('preliminary_refund_requests')->where('id', $foreignRequest->id)->first()) === $foreignBefore, 'Cross-case child changed.');
        pgCheck(Document::where('tax_case_id', $foreign->id)->count() === ($left === 'receipt' ? 2 : 1) && RefundProcess::where('tax_case_id', $foreign->id)->count() === ($left === 'receipt' ? 1 : 0), 'Foreign financial/evidence rows changed.');
    }
    echo json_encode(['race' => $label, 'workers' => $outcomes, 'status' => $request->approval_status, 'requests' => 1, 'refunds' => $refunds->count(), 'documents' => $documents->count(), 'files' => count($files), 'residue' => 0, 'transfers' => $transfers->count(), 'history' => $history, 'lock_contention' => true, 'locks_released' => true], JSON_THROW_ON_ERROR).PHP_EOL;
}
// Authorization must refuse before waiting on another transaction's parent lock.
[$protected, $owner] = pgFixture('AUTH'.Entity::count());
[$other, $outsider] = pgFixture('OUT'.Entity::count());
$barrier = $root.'/unauthorized';
$spec = ['case' => $protected->id, 'user' => $outsider->id, 'op' => 'submit', 'leader' => false, 'name' => 'unauthorizedB', 'result' => $barrier.'.json'];
file_put_contents($barrier.'.input.json', json_encode($spec, JSON_THROW_ON_ERROR));
DB::beginTransaction();
try {
    TaxCase::whereKey($protected->id)->lockForUpdate()->firstOrFail();
    $process = proc_open([PHP_BINARY, base_path('tests/run-kian-disposable.php'), 'pgsql', 'preliminary-worker', $barrier.'.input.json'], [0 => ['pipe', 'r'], 1 => ['file', $barrier.'.log', 'w'], 2 => ['file', $barrier.'.err', 'w']], $pipes, base_path());
    pgCheck(is_resource($process), 'Unauthorized worker launch failed.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 10;
    while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
        usleep(20000);
    }
    if (proc_get_status($process)['running']) {
        proc_terminate($process);
        throw new RuntimeException('Unauthorized command waited on parent lock.');
    }
    proc_close($process);
    pgCheck(is_file($barrier.'.json'), 'Unauthorized worker failed.');
    $result = json_decode(file_get_contents($barrier.'.json'), true, flags: JSON_THROW_ON_ERROR);
    pgCheck($result['outcome'] === 'refused' && $result['class'] === Illuminate\Auth\Access\AuthorizationException::class, 'Unauthorized command accepted.');
    pgCheck(! PreliminaryRefundRequest::withTrashed()->where('tax_case_id', $protected->id)->exists() && ! Document::withTrashed()->where('tax_case_id', $protected->id)->exists() && Storage::disk('local')->allFiles('tax_cases/'.$protected->id) === [], 'Denied mutation persisted evidence.');
    echo 'PASS: independent unauthorized worker refused while parent lock held; zero requests/documents/files.'.PHP_EOL;
} finally {
    DB::rollBack();
}
echo 'PASS: ten independent-process, deterministically contended preliminary races plus locked-parent authorization denial.'.PHP_EOL;
