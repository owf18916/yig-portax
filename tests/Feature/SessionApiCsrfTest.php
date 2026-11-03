<?php

namespace Tests\Feature;

use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\Period;
use App\Models\PreliminaryRefundRequest;
use App\Models\RefundProcess;
use App\Models\Role;
use App\Models\TaxCase;
use App\Models\User;
use App\Models\WorkflowHistory;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionApiCsrfTest extends TestCase
{
    use RefreshDatabase;

    private array $jar = [];

    private function cachedRoutes(): void
    {
        $path = str_replace('\\', '/', storage_path('framework/testing/csrf-routes.php'));
        $cache = preg_match('/^[A-Za-z]:\//', $path) ? substr($path, 2) : $path;
        $repository = Env::getRepository();
        $previous = $repository->get('APP_ROUTES_CACHE');
        $repository->set('APP_ROUTES_CACHE', $cache);
        try {
            $this->assertSame(realpath(dirname($path)), realpath(dirname(app()->getCachedRoutesPath())));
            $this->assertSame(0, Artisan::call('route:cache'));
            \Illuminate\Container\Container::setInstance($this->app);
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this->app);
            \Illuminate\Support\Facades\Facade::clearResolvedInstances();
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($this->app['db']);
            \Illuminate\Database\Eloquent\Model::setEventDispatcher($this->app['events']);
            require $path;
            $this->assertInstanceOf(\Illuminate\Routing\CompiledRouteCollection::class, app('router')->getRoutes());
        } finally {
            $previous === null ? $repository->clear('APP_ROUTES_CACHE') : $repository->set('APP_ROUTES_CACHE', $previous);
        }
    }

    public function test_cached_routes_preserve_real_csrf_and_guest_contracts(): void
    {
        $this->cachedRoutes();
        $this->test_real_verifier_login_rotation_precedence_logout_and_expiry();
    }

    public function test_cached_unsafe_inventory_blocks_all_missing_and_bad_tokens(): void
    {
        $this->cachedRoutes();
        $this->test_every_registered_unsafe_api_route_blocks_before_binding_or_writing();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);
        $this->assertSame('array', config('session.driver'));
        $this->assertStringStartsWith(sys_get_temp_dir(), storage_path());
        $this->assertStringContainsString('portax_auth_', storage_path());
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        Storage::fake('local'); // This fake root is inside the runner's fresh storage.
        // Keep APP_ENV=testing and the exact installed verifier. Only model the HTTP
        // runtime flag: its bypass requires BOTH runningInConsole and runningUnitTests.
        (new \ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), false);
        $this->assertFalse(app()->runningInConsole());
        $verifier = app(ValidateCsrfToken::class);
        $this->assertSame(ValidateCsrfToken::class, get_class($verifier));
        $this->assertFalse((new \ReflectionMethod($verifier, 'runningUnitTests'))->invoke($verifier));
        $this->assertSame([], $verifier->getExcludedPaths());
        Queue::fake();
    }

    private function http(string $method, string $url, array $data = [], ?string $token = null, array $extra = [], bool $json = false)
    {
        // Drop request-local state; restore identity exclusively from encrypted cookies.
        Auth::forgetGuards();
        session()->flush();
        session()->setId(null);
        $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
        if ($token !== null) {
            $server['HTTP_X_XSRF_TOKEN'] = $token;
        }
        if ($json) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $response = $this->call($method, $url, $json ? [] : $data, $this->jar, [], $server + $extra, $json ? json_encode($data) : null);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->jar[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function login(): User
    {
        $entity = Entity::create(['code' => 'CSRF', 'name' => 'Disposable', 'entity_type' => 'AFFILIATE', 'tax_id' => 'CSRF']);
        $role = Role::create(['code' => 'CSRF', 'name' => 'Disposable', 'permissions' => ['submit_cases'], 'is_active' => true]);
        $user = User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'is_active' => true]);
        $this->http('GET', '/api/csrf-token')->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $token = $this->jar['XSRF-TOKEN'];
        $session = $this->jar[config('session.cookie')];
        $anonymous = $this->jar;
        $this->http('POST', '/api/login', ['email' => $user->email, 'password' => 'password'], $token)->assertOk();
        $this->assertTrue($token !== $this->jar['XSRF-TOKEN'], 'Token rotated');
        $this->assertTrue($session !== $this->jar[config('session.cookie')], 'Session rotated');
        // Encrypted cookie inequality alone only proves different ciphertext/IV.
        // Reject the old token with the NEW authenticated session to prove rotation.
        $this->http('POST', '/api/tax-cases', [], $token)->assertStatus(419);
        $authenticated = $this->jar;
        $this->jar = $anonymous;
        $this->http('GET', '/api/me')->assertUnauthorized();
        $this->jar = $authenticated;
        $this->http('GET', '/api/me')->assertOk()->assertJsonPath('data.id', $user->id);

        return $user;
    }

    private function createCase(string $type, string $method): TaxCase
    {
        $user = $this->login();
        $this->seed([\Database\Seeders\CaseStatusesSeeder::class, \Database\Seeders\CurrenciesSeeder::class]);
        $year = FiscalYear::create(['year' => 2025, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $period = Period::create(['fiscal_year_id' => $year->id, 'period_code' => '2025-03', 'year' => 2025, 'month' => 3, 'start_date' => '2025-03-01', 'end_date' => '2025-03-31']);
        $payload = ['entity_id' => $user->entity_id, 'fiscal_year_id' => $year->id, 'period_id' => $period->id,
            'case_type' => $type, 'spt_position_type' => 'LB', 'spt_type' => $method, 'disputed_amount' => '1000.00'];
        if ($type === 'VAT') {
            $payload += ['vat_in_amount' => '2000.00', 'vat_out_amount' => '1000.00'];
        }
        $this->http('POST', '/api/tax-cases', $payload, null, [], true)->assertStatus(419);
        $this->assertDatabaseCount('tax_cases', 0);
        $result = $this->http('POST', '/api/tax-cases', $payload, $this->jar['XSRF-TOKEN'], [], true)->assertCreated();

        return TaxCase::findOrFail($result->json('data.id'));
    }

    public static function filings(): array
    {
        return [['VAT', 'Restitusi'], ['VAT', 'Kompensasi'], ['VAT', 'Pengembalian Pendahuluan'], ['CIT', 'Restitusi']];
    }

    #[DataProvider('filings')]
    public function test_real_cookie_json_case_creation_and_private_multipart(string $type, string $method): void
    {
        $case = $this->createCase($type, $method);
        $this->assertSame($method, $case->spt_type);
        $this->assertDatabaseCount('tax_cases', 1);
        $body = ['file' => UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\n%%EOF"), 'tax_case_id' => $case->id,
            'documentable_type' => WorkflowHistory::class, 'documentable_id' => 1, 'stage_code' => '1', 'document_type' => 'supporting_document'];
        $this->http('POST', '/api/documents', $body, 'bad')->assertStatus(419);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $response = $this->http('POST', '/api/documents', $body, $this->jar['XSRF-TOKEN'])->assertCreated();
        $id = $response->json('data.id');
        $this->http('GET', "/api/documents/$id/view")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->http('GET', "/api/documents/$id/download")->assertOk();
        $this->http('DELETE', "/api/documents/$id", [], 'bad')->assertStatus(419);
        $this->assertDatabaseCount('documents', 1);
        $this->http('DELETE', "/api/documents/$id", [], $this->jar['XSRF-TOKEN'])->assertOk();
    }

    public function test_real_preliminary_pdf_decision_full_receipt_closed_and_legacy_refusal(): void
    {
        $case = $this->createCase('VAT', 'Pengembalian Pendahuluan');
        // Fixture prerequisite: stage 1 has been filed. No business bypass route.
        WorkflowHistory::create(['tax_case_id' => $case->id, 'stage_id' => 1, 'action' => 'submitted', 'status' => 'submitted', 'user_id' => $case->user_id]);
        $base = '/api/tax-cases/'.$case->id;
        $token = $this->jar['XSRF-TOKEN'];
        $pdf = fn () => UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\nevidence\n%%EOF");
        $body = ['submission_date' => '2026-10-30', 'requested_amount' => '900.12', 'document' => $pdf()];
        $this->http('POST', $base.'/preliminary-refund-request', $body, 'bad')->assertStatus(419);
        $this->assertDatabaseCount('preliminary_refund_requests', 0);
        $this->http('POST', $base.'/preliminary-refund-request', $body, $token)->assertCreated();
        $request = PreliminaryRefundRequest::sole();
        $this->assertSame('SUBMITTED', $request->approval_status);
        $decision = ['approved_amount' => '800.12', 'decision_date' => '2026-10-30', 'document' => $pdf()];
        $url = $base.'/preliminary-refund-request/'.$request->id;
        $this->http('POST', $url.'/approve', $decision, 'bad')->assertStatus(419);
        $this->assertDatabaseCount('refund_processes', 0);
        $this->http('POST', $url.'/approve', $decision, $token)->assertOk();
        $refund = RefundProcess::sole();
        $payment = $base.'/refunds/'.$refund->id.'/refund-stages/';
        $this->http('PUT', $url, [], 'bad')->assertStatus(419);
        $this->http('PUT', $url, [], $token)->assertUnprocessable();
        $this->http('DELETE', $url, [], 'bad')->assertStatus(419);
        $this->http('DELETE', $url, [], $token)->assertUnprocessable();
        $this->http('POST', $payment.'2', ['request_number' => 'REQ-1', 'request_date' => '2026-10-30', 'transfer_date' => '2026-10-30'], $token)->assertOk();
        $this->http('POST', $payment.'3', ['instruction_number' => 'INS-1', 'instruction_issue_date' => '2026-10-30', 'instruction_received_date' => '2026-10-30'], $token)->assertOk();
        $receipt = ['receipt_number' => 'RCPT-1', 'received_date' => '2026-10-30', 'received_amount' => '800.12'];
        $this->http('POST', $payment.'4', $receipt, 'bad')->assertStatus(419);
        $this->assertFalse($case->fresh()->is_completed);
        $this->http('POST', $payment.'4', $receipt, $token, [], true)->assertOk();
        $this->assertSame('CLOSED', $case->fresh()->status->code);
        $this->http('POST', $payment.'4', $receipt, $token, [], true)->assertOk();
        $this->assertDatabaseCount('refund_processes', 1);
        $this->assertDatabaseCount('bank_transfer_requests', 1);
        $this->assertDatabaseCount('status_histories', 1);
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_valid_cookie_retains_creation_entity_role_and_inactive_denials(): void
    {
        $case = $this->createCase('VAT', 'Restitusi');
        $payload = $case->only(['entity_id', 'fiscal_year_id', 'period_id', 'case_type', 'spt_position_type', 'spt_type', 'vat_in_amount', 'vat_out_amount', 'disputed_amount']);
        $other = Entity::create(['code' => 'OTHER', 'name' => 'Other', 'entity_type' => 'AFFILIATE', 'tax_id' => 'OTHER']);
        $token = $this->jar['XSRF-TOKEN'];
        $this->http('POST', '/api/tax-cases', array_replace($payload, ['entity_id' => $other->id]), $token, [], true)->assertForbidden();
        $user = User::findOrFail($case->user_id);
        $user->update(['is_active' => false]);
        $this->http('POST', '/api/tax-cases', $payload, $token, [], true)->assertForbidden();
        $user->update(['is_active' => true]);
        $user->role->update(['permissions' => []]);
        $this->http('POST', '/api/tax-cases', $payload, $token, [], true)->assertForbidden();
        $this->assertDatabaseCount('tax_cases', 1);
    }

    public function test_real_stage_ranges_and_registered_legacy_paths_remain_guarded(): void
    {
        $case = $this->createCase('VAT', 'Restitusi');
        $base = '/api/tax-cases/'.$case->id;
        $histories = DB::table('workflow_histories')->count();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = true;
            }
        });
        foreach (range(1, 15) as $stage) {
            $this->http('POST', $base.'/workflow/'.$stage)->assertStatus(419);
            $this->http('POST', $base.'/workflow/'.$stage, [], 'bad')->assertStatus(419);
        }
        foreach ([4, 7, 10, 12] as $stage) {
            foreach (['POST', 'PUT'] as $method) {
                $this->http($method, $base.'/kian-submissions/'.$stage)->assertStatus(419);
                $this->http($method, $base.'/kian-submissions/'.$stage, [], 'bad')->assertStatus(419);
                $this->http($method, $base.'/kian-submissions/'.$stage, [], $this->jar['XSRF-TOKEN'])->assertStatus($method === 'PUT' ? 404 : 422);
            }
        }
        $this->assertSame([], $writes);
        foreach (range(1, 12) as $stage) {
            $response = $this->http('POST', $base.'/workflow/'.$stage, [], $this->jar['XSRF-TOKEN']);
            $this->assertContains($response->status(), [409, 422], 'Valid token preserves workflow prerequisite/validation rejection');
        }
        foreach ([13, 14, 15] as $stage) {
            $this->http('POST', $base.'/workflow/'.$stage, [], $this->jar['XSRF-TOKEN'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('workflow_histories', $histories);
        Queue::assertNothingPushed();
    }

    public function test_real_admin_methods_revision_validation_notification_denial_and_safe_verbs(): void
    {
        $case = $this->createCase('VAT', 'Restitusi');
        $token = $this->jar['XSRF-TOKEN'];
        $this->http('HEAD', '/api/csrf-token')->assertNoContent();
        $this->http('OPTIONS', '/api/csrf-token')->assertOk();
        $this->http('GET', '/api/documents/999999/view')->assertNotFound();
        $this->http('PUT', '/api/exchange-rates', [], $token, [], true)->assertUnprocessable();
        $this->http('POST', '/api/announcements', [], $token, [], true)->assertUnprocessable();
        $result = $this->http('POST', '/api/announcements', ['title' => 'Test', 'content' => 'Disposable', 'type' => 'info'], $token, [], true)->assertCreated();
        $url = '/api/announcements/'.$result->json('data.id');
        foreach (['PUT', 'PATCH'] as $method) {
            $this->http($method, $url, ['title' => 'Changed'], 'bad', [], true)->assertStatus(419);
            $this->http($method, $url, ['title' => 'Changed'], $token, [], true)->assertOk();
        }
        $this->http('DELETE', $url, [], $token)->assertOk();
        $base = '/api/tax-cases/'.$case->id;
        $this->http('POST', $base.'/revisions/request', [], $token)->assertUnprocessable();
        $log = \App\Models\NotificationLog::create(['tax_case_id' => $case->id, 'entity_id' => $case->entity_id, 'stage_id' => 4,
            'notification_type' => 'KIAN_REMINDER', 'idempotency_key' => 'csrf-fixture', 'current_status' => 'QUEUED', 'total_attempts' => 0]);
        $this->http('POST', '/api/notification-logs/'.$log->id.'/retry', [], 'bad')->assertStatus(419);
        $this->http('POST', '/api/notification-logs/'.$log->id.'/retry', [], $token)->assertForbidden();
        $this->assertDatabaseCount('notification_attempts', 0);
        Queue::assertNothingPushed();
    }

    public function test_rejected_preliminary_evidence_is_immutable_with_valid_cookie(): void
    {
        $case = $this->createCase('VAT', 'Pengembalian Pendahuluan');
        WorkflowHistory::create(['tax_case_id' => $case->id, 'stage_id' => 1, 'action' => 'submitted', 'status' => 'submitted', 'user_id' => $case->user_id]);
        $url = '/api/tax-cases/'.$case->id.'/preliminary-refund-request';
        $pdf = fn () => UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\nevidence\n%%EOF");
        $token = $this->jar['XSRF-TOKEN'];
        $this->http('POST', $url, ['submission_date' => '2026-10-30', 'requested_amount' => '900.12', 'document' => $pdf()], $token)->assertCreated();
        $request = PreliminaryRefundRequest::sole();
        $url .= '/'.$request->id;
        $body = ['reason' => 'External decision', 'decision_date' => '2026-10-30', 'document' => $pdf()];
        $this->http('POST', $url.'/reject', $body, 'bad')->assertStatus(419);
        $this->assertSame('SUBMITTED', $request->fresh()->approval_status);
        $this->http('POST', $url.'/reject', $body, $token)->assertOk();
        $this->assertSame('REJECTED', $request->fresh()->approval_status);
        $this->http('POST', $url.'/approve', $body, $token)->assertUnprocessable();
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseCount('refund_processes', 0);
        Queue::assertNothingPushed();
    }

    public function test_real_verifier_login_rotation_precedence_logout_and_expiry(): void
    {
        $this->http('POST', '/api/login')->assertStatus(419);
        $this->http('POST', '/api/login', [], 'bad')->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
        $this->http('POST', '/api/tax-cases')->assertUnauthorized();
        $user = $this->login();
        $token = $this->jar['XSRF-TOKEN'];
        $this->http('POST', '/api/tax-cases', [], $token)->assertUnprocessable();
        $this->http('POST', '/api/tax-cases', [], session()->token())->assertStatus(419);
        $this->http('POST', '/api/tax-cases', [], rawurlencode($token))->assertStatus(419);
        $this->http('POST', '/api/users', [], $token)->assertForbidden();
        $this->http('POST', '/api/tax-cases', ['_token' => 'stale'], $token)->assertStatus(419);
        $this->http('POST', '/api/tax-cases', [], $token, ['HTTP_X_CSRF_TOKEN' => 'stale'])->assertStatus(419);
        $this->assertDatabaseCount('tax_cases', 0);
        $before = $this->jar;
        $this->http('POST', '/api/logout', [], $token)->assertOk();
        $this->assertTrue($before['XSRF-TOKEN'] !== $this->jar['XSRF-TOKEN'], 'Logout token rotated');
        $this->http('GET', '/api/me')->assertUnauthorized();
        $this->http('POST', '/api/logout', [], $token)->assertUnauthorized();
        $this->jar = $before;
        $this->http('GET', '/api/me')->assertUnauthorized();
        $this->http('POST', '/api/login', ['email' => $user->email, 'password' => 'password'], $token)->assertStatus(419);
        $this->http('GET', '/api/csrf-token')->assertNoContent();
        $this->http('POST', '/api/login', ['email' => $user->email, 'password' => 'bad'], $this->jar['XSRF-TOKEN'])->assertUnauthorized();
        $this->assertDatabaseCount('tax_cases', 0);
    }

    public function test_expired_cookie_bootstrap_is_anonymous_and_explicit_login_recovers(): void
    {
        $user = $this->login();
        $old = $this->jar['XSRF-TOKEN'];
        $this->travel(config('session.lifetime') + 1)->minutes();
        $this->http('GET', '/api/me')->assertUnauthorized();
        $this->http('POST', '/api/tax-cases', [], $old)->assertUnauthorized();
        $this->http('GET', '/api/csrf-token')->assertNoContent();
        $this->assertTrue($old !== $this->jar['XSRF-TOKEN'], 'Expired session token replaced');
        $this->http('GET', '/api/me')->assertUnauthorized();
        $this->http('POST', '/api/login', ['email' => $user->email, 'password' => 'password'], $this->jar['XSRF-TOKEN'])->assertOk();
        $this->http('GET', '/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->travelBack();
    }

    public function test_every_registered_unsafe_api_route_blocks_before_binding_or_writing(): void
    {
        $this->login();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = strtok(ltrim($query->sql), ' ');
            }
        });
        $router = app('router');
        $count = 0;
        $inventory = [];
        foreach ($router->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) as $method) {
                $url = '/'.preg_replace_callback('/\{([^}]+)\}/', fn ($m) => $m[1] === 'stageId' ? '4' : '999999', $route->uri());
                $matched = $router->getRoutes()->match(Request::create($url, $method));
                $middleware = $router->gatherRouteMiddleware($matched);
                $inventory[] = ['method' => $method, 'registered' => $route->uri(), 'matched' => $matched->uri(), 'middleware' => $middleware];
                $csrf = array_search(ValidateCsrfToken::class, $middleware, true);
                $this->assertNotFalse($csrf, "$method $url");
                foreach ($middleware as $index => $class) {
                    if (is_a(explode(':', $class)[0], AuthenticatesRequests::class, true)) {
                        $this->assertLessThan($csrf, $index, "$method $url auth order");
                    }
                }
                $this->http($method, $url)->assertStatus(419);
                $this->http($method, $url, [], 'invalid')->assertStatus(419);
                $count++;
            }
        }
        $this->assertSame(81, $count);
        $this->assertSame([], $writes, 'No controller/database writes for any missing/bad token');
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
        $mode = $router->getRoutes() instanceof \Illuminate\Routing\CompiledRouteCollection ? 'cached' : 'uncached';
        file_put_contents(storage_path('framework/testing/csrf-route-evidence-'.$mode.'.json'), json_encode($inventory, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
