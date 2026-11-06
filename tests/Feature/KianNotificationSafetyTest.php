<?php

namespace Tests\Feature;

use App\Jobs\SendKianReminderJob;
use App\Mail\KianReminderMail;
use App\Models\CaseStatus;
use App\Models\Currency;
use App\Models\Entity;
use App\Models\FiscalYear;
use App\Models\KianSubmission;
use App\Models\NotificationAttempt;
use App\Models\NotificationLog;
use App\Models\Revision;
use App\Models\Role;
use App\Models\SkpRecord;
use App\Models\TaxCase;
use App\Models\User;
use App\Services\KianNotificationService;
use App\Services\KianReminderRecipients;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KianNotificationSafetyTest extends TestCase
{
    private TaxCase $case;

    private User $actor;

    private SkpRecord $decision;

    private KianNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);
        $this->assertStringStartsWith(sys_get_temp_dir(), storage_path());
        $this->artisan('migrate', ['--force' => true])->run();
        Mail::fake();
        Queue::fake();
        (new RolesSeeder)->run();
        $entity = Entity::create(['code' => 'NS', 'name' => 'Synthetic affiliate', 'entity_type' => 'AFFILIATE', 'tax_id' => 'NS']);
        $this->actor = User::factory()->create(['email' => 'owner@example.test', 'entity_id' => $entity->id, 'role_id' => Role::where('code', 'ADMIN')->value('id'), 'is_active' => true]);
        $year = FiscalYear::create(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $currency = Currency::create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp']);
        $status = CaseStatus::create(['code' => 'OPEN', 'name' => 'Open']);
        $this->case = TaxCase::create(['case_number' => 'NS-1', 'case_type' => 'CIT', 'entity_id' => $entity->id, 'user_id' => $this->actor->id,
            'fiscal_year_id' => $year->id, 'currency_id' => $currency->id, 'case_status_id' => $status->id, 'spt_position_type' => 'LB',
            'disputed_amount' => '1000.01', 'reported_amount' => '1000.01', 'current_stage' => 4]);
        $this->decision = SkpRecord::create(['tax_case_id' => $this->case->id, 'skp_number' => 'NS-SKP', 'skp_type' => 'NIHIL', 'skp_amount' => '0.00']);
        $this->case->workflowHistories()->create(['stage_id' => 4, 'action' => 'submitted', 'status' => 'submitted', 'user_id' => $this->actor->id]);
        $this->service = app(KianNotificationService::class);
        $this->actingAs($this->actor);
    }

    private function queued(): NotificationLog
    {
        return $this->service->evaluateFinalSubmit($this->case->fresh(), 4);
    }

    private function runAttempt(NotificationLog $log): NotificationAttempt
    {
        $attempt = $log->attempts()->latest('attempt_number')->firstOrFail();
        (new SendKianReminderJob($attempt->id))->handle();

        return $attempt->fresh();
    }

    private function entity(string $code, string $type = 'AFFILIATE'): Entity
    {
        return Entity::create(['code' => $code, 'name' => $code, 'entity_type' => $type, 'tax_id' => $code]);
    }

    private function user(Entity $entity, Role $role, string $email, bool $active = true): User
    {
        return User::factory()->create(['entity_id' => $entity->id, 'role_id' => $role->id, 'email' => $email, 'is_active' => $active]);
    }

    private function role(string $name, bool $active = true): Role
    {
        return Role::create(['code' => str_replace(' ', '_', $name), 'name' => $name, 'is_active' => $active, 'permissions' => []]);
    }

    private function noTo(): NotificationLog
    {
        $this->actor->update(['is_active' => false]);
        $log = $this->queued();
        $this->assertSame(NotificationLog::NO_VALID_TO, $this->runAttempt($log)->status);
        $this->actor->update(['is_active' => true]);

        return $log->fresh();
    }

    public function test_permission_seed_is_idempotent_additive_and_active_admin_only(): void
    {
        $role = $this->actor->role;
        $role->update(['permissions' => ['custom_assignment']]);
        (new RolesSeeder)->run();
        (new RolesSeeder)->run();
        $this->assertSame(['custom_assignment', 'retry_notifications'], $role->fresh()->permissions);
        $this->assertSame(1, Role::whereJsonContains('permissions', 'retry_notifications')->count());
        $this->assertSame(3, Role::count());
        $role->update(['permissions' => [], 'is_active' => false]);
        (new RolesSeeder)->run();
        $this->assertSame([], $role->fresh()->permissions);
    }

    public function test_grouped_send_snapshot_and_duplicate_delivery_are_safe(): void
    {
        $domain = $this->case->fresh()->getAttributes();
        $log = $this->queued();
        $snapshot = $log->event_snapshot;
        $attempt = $this->runAttempt($log);
        $this->assertSame(NotificationLog::SENT, $attempt->status);
        $this->assertNotNull($attempt->send_boundary_at);
        $this->assertSame('1000.01', $attempt->message_snapshot['event']['exposure_amount']);
        $this->assertStringContainsString('IDR 1000.01', $attempt->message_snapshot['html']);
        $this->assertSame($this->actor->id, $attempt->recipient_snapshot['identities']['to']['owner@example.test'][0]['user_id']);
        Mail::assertSent(KianReminderMail::class, fn ($mail) => $mail->hasTo('owner@example.test') && count($mail->bcc) === 0);
        (new SendKianReminderJob($attempt->id))->handle();
        $this->service->evaluateFinalSubmit($this->case->fresh(), 4);
        Mail::assertSentCount(1);
        $this->assertSame(1, NotificationAttempt::count());
        $this->actor->update(['email' => 'changed@example.test']);
        $this->case->update(['case_number' => 'NS-CHANGED']);
        $this->assertSame($snapshot, $log->fresh()->event_snapshot);
        $this->assertSame($attempt->message_snapshot, $attempt->fresh()->message_snapshot);
        $this->assertSame($domain, array_replace($this->case->fresh()->getAttributes(), ['case_number' => $domain['case_number'], 'updated_at' => $domain['updated_at']]));
        $this->assertDatabaseCount('refund_processes', 0);
        $this->assertDatabaseCount('kian_submissions', 0);
    }

    public function test_new_message_is_complete_immutable_and_mail_rendering_has_no_queries(): void
    {
        $this->case->update(['case_number' => 'SA20MarC <script>alert(1)</script>']);
        $log = $this->queued();
        $snapshot = $log->latestAttempt->message_snapshot;
        $this->assertSame('CIT', $snapshot['values']['caseType']);
        $this->assertSame(2026, $snapshot['values']['taxYear']);
        $this->assertSame('SKP Assessment', $snapshot['values']['stageName']);
        $this->assertStringContainsString('SA20MarC &lt;script&gt;', $snapshot['html']);
        $this->assertStringNotContainsString('<script>', $snapshot['html']);
        $this->assertStringContainsString('IDR 1000.01', $snapshot['text']);
        $this->assertSame(url('/tax-cases/'.$this->case->id), $snapshot['values']['caseUrl']);
        $this->case->update(['case_number' => 'Later case', 'case_type' => 'VAT']);
        $this->case->entity->update(['name' => 'Later taxpayer']);
        $this->case->fiscalYear->update(['year' => 2027]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $mail = new KianReminderMail($snapshot);
        $html = $mail->render();
        $mime = $mail->withSymfonyMessage(function ($message) use ($snapshot) {
            $this->assertSame($snapshot['text']."\n", $message->getTextBody());
            $this->assertSame($snapshot['subject'], $message->getSubject());
        });
        // Array transport builds real multipart MIME without a network connection.
        app('mail.manager')->mailer('array')->send($mime->to('synthetic@example.test'));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($snapshot['html'], $html);
        $this->assertStringNotContainsString('Later taxpayer', $html);
        $this->assertSame($snapshot, $log->latestAttempt->fresh()->message_snapshot);
        $this->assertSame($snapshot['html'], (new KianReminderMail(['subject' => $snapshot['subject'], 'html' => $snapshot['html']]))->render());
    }

    public static function emailStages(): array
    {
        return [[4, 'SKP Assessment'], [7, 'Objection Decision'], [10, 'Appeal Decision'], [12, 'Supreme Court Decision'], [null, null]];
    }

    #[DataProvider('emailStages')]
    public function test_english_sections_exact_amount_structure_and_fallbacks(?int $stage, ?string $name): void
    {
        $values = $stage ? ['stage' => $stage, 'stageName' => $name, 'caseNumber' => 'Synthetic & case',
            'currency' => 'USD', 'amount' => '999999999999999999.01', 'caseUrl' => 'https://portax.example.test/tax-cases/4'] : [];
        foreach (['emails.kian-reminder-snapshot', 'emails.kian-reminder-snapshot-text'] as $view) {
            $rendered = view($view, $values)->render();
            foreach (['KIAN submission required', 'Action Required', 'Case Information', 'Case Number', 'Case Type', 'Tax Year', 'Taxpayer',
                'Current Stage', 'Remaining Company Tax Exposure', 'Next Steps', 'automated notification', 'All rights reserved.'] as $copy) {
                $this->assertStringContainsString($copy, $rendered);
            }
            $this->assertDoesNotMatchRegularExpression('/Informasi Kasus|Tahun Pajak|Wajib Pajak|Tahap Saat Ini|Kerugian|Langkah Selanjutnya|Lihat Kasus|Catatan|silakan|Anda|dikirimkan|mengajukan/i', $rendered);
            if ($stage) {
                $this->assertStringContainsString($name, $rendered);
                $this->assertStringContainsString('USD 999999999999999999.01', $rendered);
                $this->assertStringContainsString('View Tax Case', $rendered);
            } else {
                $this->assertStringContainsString('Not available', $rendered);
                $this->assertStringContainsString('case link is not available', $rendered);
            }
        }
        $html = view('emails.kian-reminder-snapshot', $values)->render();
        $this->assertStringContainsString('<!--[if mso]>', $html);
        $this->assertStringContainsString('max-width:600px', $html);
        $this->assertStringContainsString('scope="row"', $html);
        $this->assertSame(substr_count($html, '<table'), substr_count($html, '</table>'));
        $this->assertDoesNotMatchRegularExpression('/<script|<img|<link|xmlns:|number_format/', $html);
    }

    public function test_seed_shaped_holding_manager_has_no_pic_rule_but_approved_cc_is_grouped(): void
    {
        $this->case->update(['case_number' => 'SA20MarC']);
        $holding = $this->entity('PASI-SYNTHETIC', 'HOLDING');
        $this->case->entity->update(['parent_entity_id' => $holding->id]);
        $pic = $this->user($holding, Role::where('code', 'MANAGER')->firstOrFail(), 'novia.hardianti@example.test');
        $this->assertSame([], app(KianReminderRecipients::class)->resolve($this->case->fresh())['cc']);
        // A test-only approved category proves the delivery plumbing; it does not assign the real user a role.
        $pic->update(['role_id' => $this->role('Coordinator')->id]);
        $log = $this->queued();
        $attempt = $this->runAttempt($log);
        $this->assertSame(['novia.hardianti@example.test'], $attempt->cc_recipients);
        $this->assertSame($attempt->cc_recipients, $attempt->recipient_snapshot['cc']);
        Mail::assertSent(KianReminderMail::class, fn ($mail) => $mail->hasCc('novia.hardianti@example.test') && count($mail->cc) === 1);
        Mail::assertSentCount(1);
        $pic->update(['email' => 'later@example.test']);
        $this->assertSame($attempt->recipient_snapshot, $attempt->fresh()->recipient_snapshot);
        $holding->update(['is_active' => false]);
        $this->assertSame([], app(KianReminderRecipients::class)->resolve($this->case->fresh())['cc']);
        $holding->update(['is_active' => true]);
        $this->case->entity->update(['entity_type' => 'HOLDING']);
        $this->assertSame([], app(KianReminderRecipients::class)->resolve($this->case->fresh())['cc']);
    }

    public function test_recipient_matrix_and_query_growth(): void
    {
        $holding = $this->entity('H', 'HOLDING');
        $foreign = $this->entity('F');
        $this->case->entity->update(['parent_entity_id' => $holding->id]);
        $manager = Role::where('code', 'MANAGER')->firstOrFail();
        $coordinator = $this->role('Coordinator');
        $gm = $this->role('General Manager', false);
        $this->user($this->case->entity, $manager, 'OWNER@EXAMPLE.TEST');
        $this->user($holding, $coordinator, 'owner@EXAMPLE.test');
        $this->user($holding, $coordinator, 'cc@example.test');
        $this->user($holding, $coordinator, 'inactive@example.test', false);
        $this->user($holding, $gm, 'inactive-role@example.test');
        $this->user($foreign, $manager, 'foreign@example.test');
        $this->user($foreign, $coordinator, 'foreign-coordinator@example.test');
        $this->user($this->entity('OTHER-H', 'HOLDING'), $coordinator, 'other-holding@example.test');
        $this->user($this->case->entity, $manager, 'invalid');
        $resolver = app(KianReminderRecipients::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = $resolver->resolve($this->case->fresh());
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(['owner@example.test'], $result['to']);
        $this->assertSame(['cc@example.test'], $result['cc']);
        $this->assertLessThanOrEqual(5, $count);
        for ($i = 0; $i < 30; $i++) {
            $this->user($this->case->entity, $manager, "manager{$i}@example.test");
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $resolver->resolve($this->case->fresh());
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
        fwrite(STDOUT, "B18.1 recipient SQL statements: small={$count}; +30 recipients={$count}\n");
        $this->actor->update(['entity_id' => $foreign->id]);
        $ids = array_merge(...array_values($resolver->resolve($this->case->fresh())['identities']['to']));
        $this->assertNotContains($this->actor->id, array_column($ids, 'user_id'));
        $holding->update(['entity_type' => 'AFFILIATE']);
        $this->assertSame([], $resolver->resolve($this->case->fresh())['cc']);
        $this->case->entity->update(['parent_entity_id' => null]);
        $this->assertSame([], $resolver->resolve($this->case->fresh())['cc']);
    }

    public function test_no_to_is_not_sent_to_cc_and_manual_retry_freezes_old_attempt(): void
    {
        $holding = $this->entity('H', 'HOLDING');
        $this->case->entity->update(['parent_entity_id' => $holding->id]);
        $this->user($holding, $this->role('Coordinator'), 'cc@example.test');
        $log = $this->noTo();
        Mail::assertNothingSent();
        $old = $log->latestAttempt->getAttributes();
        $this->getJson('/api/notification-logs/'.$log->id)->assertOk()->assertJsonPath('can_retry', true);
        $response = $this->postJson('/api/notification-logs/'.$log->id.'/retry', ['tax_case_id' => $this->case->id])->assertAccepted();
        $newId = $response->json('attempt.id');
        $this->postJson('/api/notification-logs/'.$log->id.'/retry')->assertConflict();
        $this->assertSame($old, NotificationAttempt::findOrFail($old['id'])->getAttributes());
        $this->assertDatabaseCount('notification_attempts', 2);
        $this->assertSame($this->actor->id, NotificationAttempt::find($newId)->initiated_by);
        $this->assertSame(NotificationLog::SENT, $this->runAttempt($log)->status);
        $this->assertSame(['owner@example.test'], NotificationAttempt::find($newId)->to_recipients);
        Queue::assertPushed(SendKianReminderJob::class, 2);
        Mail::assertSentCount(1);
    }

    public static function deniedActors(): array
    {
        return array_map(fn ($v) => [$v], ['inactive_user', 'inactive_role', 'absent_role', 'permission_revoked', 'foreign', 'holding_without_permission', 'holding_without_entity', 'forged_case']);
    }

    #[DataProvider('deniedActors')]
    public function test_denied_retry_has_no_mutations(string $mode): void
    {
        $log = $this->noTo();
        if ($mode === 'inactive_user') {
            $this->actor->update(['is_active' => false]);
        }
        if ($mode === 'inactive_role') {
            $this->actor->role->update(['is_active' => false]);
        }
        if ($mode === 'absent_role') {
            $this->actor->update(['role_id' => null]);
        }
        if (in_array($mode, ['permission_revoked', 'holding_without_permission'])) {
            $this->actor->role->update(['permissions' => []]);
        }
        if (in_array($mode, ['foreign', 'holding_without_permission'])) {
            $this->actor->update(['entity_id' => $this->entity('OTHER', $mode === 'foreign' ? 'AFFILIATE' : 'HOLDING')->id]);
        }
        if ($mode === 'holding_without_entity') {
            $this->actor->update(['entity_id' => null]);
        }
        $before = [NotificationLog::first()->getAttributes(), NotificationAttempt::first()->getAttributes(), DB::table('audit_logs')->count()];
        $this->postJson('/api/notification-logs/'.$log->id.'/retry', ['tax_case_id' => $mode === 'forged_case' ? 999999 : $this->case->id])->assertStatus($mode === 'forged_case' ? 404 : 403);
        $this->assertSame($before, [NotificationLog::first()->getAttributes(), NotificationAttempt::first()->getAttributes(), DB::table('audit_logs')->count()]);
        $this->assertDatabaseCount('notification_attempts', 1);
        Queue::assertPushed(SendKianReminderJob::class, 1);
    }

    public function test_scoped_reads_and_revoked_capability(): void
    {
        $log = $this->noTo();
        $this->getJson('/api/notification-logs')->assertOk()->assertJsonPath('data.0.can_retry', true);
        $this->actor->role->update(['permissions' => []]);
        $this->getJson('/api/notification-logs/'.$log->id)->assertOk()->assertJsonPath('can_retry', false);
        $this->actor->update(['entity_id' => $this->entity('OTHER')->id]);
        $this->getJson('/api/notification-logs')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/notification-logs/'.$log->id)->assertForbidden();
        $this->getJson('/api/tax-cases/'.$this->case->id.'/notification-summary')->assertForbidden();
        $this->actor->update(['entity_id' => $this->entity('H', 'HOLDING')->id]);
        $this->getJson('/api/notification-logs/'.$log->id)->assertOk()->assertJsonPath('can_retry', false);
        $this->actor->role->update(['permissions' => ['retry_notifications']]);
        $this->postJson('/api/notification-logs/'.$log->id.'/retry')->assertAccepted();
        Auth::forgetGuards();
        $this->getJson('/api/notification-logs')->assertUnauthorized();
    }

    public function test_claim_is_conditional_and_stale_jobs_never_send(): void
    {
        $log = $this->queued();
        $attempt = $log->attempts()->first();
        $one = new SendKianReminderJob($attempt->id);
        $two = new SendKianReminderJob($attempt->id);
        $this->assertNotNull($one->claim('worker-one'));
        $this->assertNull($two->claim('worker-two'));
        $two->handle();
        Mail::assertNothingSent();
        $this->postJson('/api/notification-logs/'.$log->id.'/retry')->assertConflict();
        $this->service->evaluateFinalSubmit($this->case->fresh(), 4);
        $this->assertDatabaseCount('notification_attempts', 1);
    }

    public function test_transport_exception_is_uncertain_sanitized_and_non_retryable(): void
    {
        $log = $this->queued();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('cc')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function () use ($log) {
            $this->assertSame(NotificationLog::UNCERTAIN, $log->fresh()->current_status);
            $this->assertSame(0, DB::transactionLevel());
            throw new \RuntimeException('smtp://secret:password@private-provider/payload');
        });
        $attempt = $this->runAttempt($log);
        $this->assertSame(NotificationLog::UNCERTAIN, $attempt->status);
        $this->assertNotNull($attempt->send_boundary_at);
        $json = $this->getJson('/api/notification-logs/'.$log->id)->assertOk()->assertJsonPath('can_retry', false)->getContent();
        $this->assertStringNotContainsString('password', $json);
        $this->assertStringNotContainsString('error_message', $json);
        $this->postJson('/api/notification-logs/'.$log->id.'/retry')->assertConflict();
        $this->service->evaluateFinalSubmit($this->case->fresh(), 4);
        $this->assertDatabaseCount('notification_attempts', 1);
    }

    public function test_pre_send_exception_is_the_only_failure_retryable(): void
    {
        $log = $this->queued();
        $this->mock(KianReminderRecipients::class)->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('private connection detail'));
        $attempt = $this->runAttempt($log);
        $this->assertSame(NotificationLog::PRE_SEND_FAILED, $attempt->status);
        $this->assertNull($attempt->send_boundary_at);
        Mail::assertNothingSent();
        $this->getJson('/api/notification-logs/'.$log->id)->assertJsonPath('can_retry', true);
        $this->service->evaluateFinalSubmit($this->case->fresh(), 4);
        $this->assertDatabaseCount('notification_attempts', 1);
        $this->postJson('/api/notification-logs/'.$log->id.'/retry')->assertAccepted();
    }

    public static function obsolete(): array
    {
        return [['submitted'], ['responded'], ['closed'], ['approved'], ['zero'], ['superseded'], ['case_closed'], ['legacy'], ['entity_changed']];
    }

    #[DataProvider('obsolete')]
    public function test_obsolete_reminders_suppress_without_domain_mutation(string $mode): void
    {
        $log = $this->queued();
        if (in_array($mode, ['submitted', 'responded', 'closed', 'approved'])) {
            KianSubmission::create(['tax_case_id' => $this->case->id, 'stage_id' => 4,
                'kian_number' => 'NS-K', 'submission_date' => '2026-11-05', 'kian_amount' => '1000.01', 'status' => $mode]);
        }
        if ($mode === 'zero') {
            $this->decision->update(['skp_type' => 'LB', 'skp_amount' => '1000.01']);
        }
        if ($mode === 'superseded') {
            $this->revision('900.01');
        }
        if ($mode === 'case_closed') {
            $this->case->update(['case_status_id' => CaseStatus::create(['code' => 'CLOSED', 'name' => 'Closed'])->id]);
        }
        if ($mode === 'entity_changed') {
            $this->case->update(['entity_id' => $this->entity('MOVED')->id]);
        }
        if ($mode === 'legacy') {
            DB::table('notification_logs')->where('id', $log->id)->update(['event_snapshot' => null]);
        }
        $before = [$this->case->fresh()->getAttributes(), $this->decision->fresh()->getAttributes(), KianSubmission::all()->toArray(), Revision::all()->toArray()];
        $this->assertSame(NotificationLog::SUPPRESSED, $this->runAttempt($log)->status);
        $this->assertSame($before, [$this->case->fresh()->getAttributes(), $this->decision->fresh()->getAttributes(), KianSubmission::all()->toArray(), Revision::all()->toArray()]);
        Mail::assertNothingSent();
    }

    private function revision(string $amount): Revision
    {
        $revision = Revision::create(['revisable_type' => 'TaxCase', 'revisable_id' => $this->case->id, 'target_type' => SkpRecord::class, 'target_id' => $this->decision->id,
            'stage_code' => 4, 'revision_status' => 'approved', 'requested_by' => $this->actor->id, 'requested_at' => now(), 'reason' => 'Synthetic version',
            'approved_by' => $this->actor->id, 'approved_at' => now()]);
        $this->decision->update(['skp_type' => 'LB', 'skp_amount' => $amount]);

        return $revision;
    }

    public function test_event_versions_a_b_a_and_latest_summary_are_distinct_and_bounded(): void
    {
        $first = $this->queued();
        $this->revision('100.00');
        $second = $this->queued();
        $this->revision('0.00');
        $third = $this->queued();
        $this->queued();
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->id, $third->id);
        $this->assertDatabaseCount('notification_attempts', 3);
        $this->assertSame($first->event_snapshot['exposure_amount'], $third->event_snapshot['exposure_amount']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->service->summary($this->case->fresh(), $this->actor);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($third->id, $data[4]['id']);
        $this->assertLessThanOrEqual(8, $count);
        for ($i = 0; $i < 60; $i++) {
            NotificationLog::create(['tax_case_id' => $this->case->id, 'entity_id' => $this->case->entity_id,
                'stage_id' => 4, 'notification_type' => 'KIAN_REMINDER', 'idempotency_key' => hash('sha256', 'history'.$i), 'current_status' => 'FAILED']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $data = $this->service->summary($this->case->fresh(), $this->actor);
        $largeCount = count(DB::getQueryLog());
        $this->assertLessThanOrEqual($count, $largeCount);
        DB::disableQueryLog();
        $this->assertCount(1, $data);
        fwrite(STDOUT, "B18.1 summary SQL statements: small={$count}; +60 events={$largeCount}\n");
        $this->getJson('/api/notification-logs?per_page=999999')->assertJsonPath('per_page', 100);
        $this->getJson('/api/notification-logs?per_page=0')->assertJsonPath('per_page', 1);
    }

    public function test_legacy_failed_and_processing_are_not_proven_retryable(): void
    {
        $log = $this->queued();
        foreach (['FAILED', 'SKIPPED_NO_RECIPIENT', 'PROCESSING', 'DELIVERY_UNCERTAIN', 'SENT', 'SUPPRESSED'] as $status) {
            $log->update(['current_status' => $status]);
            $log->attempts()->update(['status' => $status, 'error_message' => 'private smtp secret']);
            $this->getJson('/api/notification-logs/'.$log->id)->assertJsonPath('can_retry', false);
            $this->postJson('/api/notification-logs/'.$log->id.'/retry')->assertConflict();
        }
        $this->assertDatabaseCount('notification_attempts', 1);
    }

    public function test_attempt_and_log_completion_roll_back_together_on_persistence_fault(): void
    {
        $log = $this->queued();
        // A failure after a possible handoff must leave durable uncertainty on both rows.
        NotificationLog::updating(function ($row) {
            if ($row->current_status === NotificationLog::SENT) {
                throw new \RuntimeException('write failed after acceptance');
            }
        });
        $attempt = $this->runAttempt($log);
        Mail::assertSentCount(1);
        $this->assertSame(NotificationLog::UNCERTAIN, $attempt->status);
        $this->assertSame(NotificationLog::UNCERTAIN, $log->fresh()->current_status);
        $this->getJson('/api/notification-logs/'.$log->id)->assertJsonPath('can_retry', false);
        (new SendKianReminderJob($attempt->id))->handle();
        Mail::assertSentCount(1);
    }

    public function test_commit_dispatch_and_rollback_use_only_in_memory_database_queue(): void
    {
        $manager = new \Illuminate\Queue\QueueManager($this->app);
        $manager->addConnector('database', fn () => new \Illuminate\Queue\Connectors\DatabaseConnector($this->app['db']));
        Queue::swap($manager);
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite', 'queue.connections.database.after_commit' => true]);
        DB::beginTransaction();
        $this->queued();
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('notification_logs', 0);
        $this->assertDatabaseCount('notification_attempts', 0);
        $this->assertDatabaseCount('jobs', 0);
        DB::beginTransaction();
        $this->queued();
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();
        $this->assertDatabaseCount('jobs', 1);
        Mail::assertNothingSent();
    }

    public function test_migration_preserves_legacy_rows_and_fails_closed_without_backfill(): void
    {
        $log = NotificationLog::create(['tax_case_id' => $this->case->id, 'entity_id' => $this->case->entity_id,
            'stage_id' => 4, 'notification_type' => 'KIAN_REMINDER', 'idempotency_key' => hash('sha256', 'legacy'), 'current_status' => 'FAILED', 'total_attempts' => 1]);
        $attempt = $log->attempts()->create(['attempt_number' => 1, 'status' => 'FAILED', 'initiated_type' => 'SYSTEM', 'error_message' => 'legacy private failure']);
        $migration = require database_path('migrations/2026_11_05_000001_harden_kian_notification_delivery.php');
        $legacy = [$log->only(['id', 'current_status', 'total_attempts']), $attempt->only(['id', 'status', 'error_message'])];
        $this->actor->role->update(['permissions' => ['custom']]);
        $migration->down();
        $migration->up();
        $this->assertSame($legacy, [$log->fresh()->only(['id', 'current_status', 'total_attempts']), $attempt->fresh()->only(['id', 'status', 'error_message'])]);
        $this->assertNull($attempt->fresh()->send_boundary_at);
        $this->assertNull($log->fresh()->event_snapshot);
        $this->assertSame(['custom', 'retry_notifications'], $this->actor->role->fresh()->permissions);
        $this->getJson('/api/notification-logs/'.$log->id)->assertJsonPath('can_retry', false);
        $this->queued();
        try {
            $migration->down();
            $this->fail('Evidence-bearing downgrade must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Preserve B18.1', $e->getMessage());
        }
    }

    public function test_immutable_evidence_and_stale_completions_cannot_rewrite_new_attempt(): void
    {
        $log = $this->noTo();
        $old = $log->latestAttempt;
        $new = $this->service->retry($log, $this->actor);
        $this->assertSame($old->message_snapshot, $new->message_snapshot);
        $job = new SendKianReminderJob($old->id);
        (new \ReflectionMethod($job, 'finish'))->invoke($job, $old, $old->claim_token, NotificationLog::SENT, 'late completion');
        $this->assertSame(NotificationLog::QUEUED, $log->fresh()->current_status);
        $this->assertSame(NotificationLog::QUEUED, $new->fresh()->status);
        foreach (['message_snapshot', 'recipient_snapshot', 'to_recipients'] as $field) {
            try {
                $old->fresh()->update([$field => ['tampered']]);
                $this->fail('Evidence is immutable.');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        try {
            $row = $log->fresh();
            $row->event_snapshot = [];
            $row->save();
            $this->fail('Event is immutable.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
        $this->assertSame(NotificationLog::NO_VALID_TO, $old->fresh()->status);
    }

    public function test_claim_failure_rolls_back_both_rows_before_transport(): void
    {
        $log = $this->queued();
        $attempt = $log->latestAttempt;
        NotificationLog::updating(function ($row) {
            if ($row->current_status === NotificationLog::PROCESSING) {
                throw new \RuntimeException('synthetic claim write failure');
            }
        });
        try {
            (new SendKianReminderJob($attempt->id))->claim('interrupted');
            $this->fail('Expected fault.');
        } catch (\RuntimeException $e) {
            $this->assertSame('synthetic claim write failure', $e->getMessage());
        }
        $this->assertSame(NotificationLog::QUEUED, $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->claim_token);
        $this->assertSame(NotificationLog::QUEUED, $log->fresh()->current_status);
        Mail::assertNothingSent();
    }

    public function test_detail_attempt_history_is_capped_and_reads_have_fixed_query_counts(): void
    {
        $log = $this->queued();
        $read = function (string $url): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->getJson($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$response, $count];
        };
        [, $listSmall] = $read('/api/notification-logs');
        [, $detailSmall] = $read('/api/notification-logs/'.$log->id);
        for ($i = 2; $i <= 75; $i++) {
            $log->attempts()->create(['attempt_number' => $i, 'status' => 'FAILED', 'initiated_type' => 'SYSTEM']);
        }
        [$response, $detailLarge] = $read('/api/notification-logs/'.$log->id);
        $response->assertJsonCount(50, 'attempts')->assertJsonPath('attempts.0.attempt_number', 75);
        [, $listLarge] = $read('/api/notification-logs');
        $this->assertLessThanOrEqual($listSmall, $listLarge);
        $this->assertLessThanOrEqual($detailSmall, $detailLarge);
        $this->assertLessThanOrEqual(12, $listLarge);
        $this->assertLessThanOrEqual(14, $detailLarge);
        fwrite(STDOUT, "B18.1 HTTP SQL statements: list={$listSmall}/{$listLarge}; detail={$detailSmall}/{$detailLarge}; attempts=1/75\n");
    }

    public function test_canonical_revision_caller_uses_committed_approval_identity(): void
    {
        $first = $this->queued();
        $revisions = app(\App\Services\RevisionService::class);
        $revision = $revisions->requestRevision($this->case->fresh(), $this->actor,
            ['skp_type' => 'LB', 'skp_amount' => '100.00'], [], 'Synthetic canonical correction', ['skp_type', 'skp_amount'], 4);
        $revisions->decideRevision($revision, $this->case->fresh(), $this->actor, 'approve');
        $this->assertSame('approved', $revision->fresh()->revision_status);
        $new = NotificationLog::latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $new->id);
        $this->assertSame($revision->id, $new->event_snapshot['revision_id']);
        $this->assertSame('900.01', $new->event_snapshot['exposure_amount']);
        $this->assertSame(NotificationLog::SUPPRESSED, $this->runAttempt($first)->status);
        $this->assertSame(NotificationLog::SENT, $this->runAttempt($new)->status);
        $this->queued();
        $this->assertDatabaseCount('notification_attempts', 2);
        Mail::assertSentCount(1);
    }

    public function test_missing_submission_provenance_fails_closed(): void
    {
        $this->case->workflowHistories()->delete(); // synthetic in-memory fixture only
        $this->assertNull($this->service->evaluateFinalSubmit($this->case->fresh(), 4));
        $this->assertDatabaseCount('notification_logs', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_final_boundary_rechecks_kian_after_recipient_selection(): void
    {
        $log = $this->queued();
        $this->app->bind(KianReminderRecipients::class, fn () => new class extends KianReminderRecipients
        {
            public function resolve(TaxCase $case): array
            {
                $result = parent::resolve($case);
                KianSubmission::create(['tax_case_id' => $case->id, 'stage_id' => 4, 'kian_number' => 'LATE-KIAN',
                    'submission_date' => '2026-11-05', 'kian_amount' => '1000.01', 'status' => 'submitted']);

                return $result;
            }
        });
        $attempt = $this->runAttempt($log);
        $this->assertSame(NotificationLog::SUPPRESSED, $attempt->status);
        $this->assertNull($attempt->send_boundary_at);
        Mail::assertNothingSent();
    }
}
