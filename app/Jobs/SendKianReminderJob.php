<?php

namespace App\Jobs;

use App\Mail\KianReminderMail;
use App\Models\NotificationAttempt;
use App\Models\NotificationLog;
use App\Models\TaxCase;
use App\Services\KianNotificationService;
use App\Services\KianReminderRecipients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendKianReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $notificationAttemptId) {}

    public function handle(): void
    {
        $token = (string) Str::uuid();
        $attempt = $this->claim($token);
        if (! $attempt) {
            return;
        }
        $boundary = false;
        try {
            $log = $attempt->notificationLog;
            $case = TaxCase::findOrFail($log->tax_case_id);
            $service = app(KianNotificationService::class);
            if ($reason = $service->suppression($case, $log->stage_id, $log->event_snapshot)) {
                $this->finish($attempt, $token, NotificationLog::SUPPRESSED, $reason);

                return;
            }
            if (! $attempt->message_snapshot || empty($attempt->message_snapshot['html'])) {
                $this->finish($attempt, $token, NotificationLog::SUPPRESSED, 'LEGACY_SNAPSHOT_UNAVAILABLE');

                return;
            }
            $recipients = app(KianReminderRecipients::class)->resolve($case);
            // Snapshot once, while this claim owns PROCESSING and before any mail call.
            if (! $this->transition($attempt, $token, NotificationLog::PROCESSING, [
                'recipient_snapshot' => $recipients, 'to_recipients' => $recipients['to'], 'cc_recipients' => $recipients['cc'], 'bcc_recipients' => [],
            ])) {
                return;
            }
            if (! $recipients['to']) {
                $this->finish($attempt, $token, NotificationLog::NO_VALID_TO, 'NO_VALID_TO');

                return;
            }
            $mail = new KianReminderMail($attempt->message_snapshot);
            if (! is_string($attempt->message_snapshot['subject'] ?? null) || ! is_string($attempt->message_snapshot['html'])) {
                throw new \LogicException('Invalid immutable message envelope.');
            }
            // A crash from here onward must never be interpreted as safely resendable.
            if (! $this->enterBoundary($attempt, $token)) {
                return;
            }
            $boundary = true;
            Mail::to($recipients['to'])->cc($recipients['cc'])->send($mail);
            $this->finish($attempt, $token, NotificationLog::SENT, 'TRANSPORT_CALL_COMPLETED');
            try {
                \App\Models\AuditLog::create(['auditable_type' => TaxCase::class, 'auditable_id' => $case->id,
                    'user_id' => $attempt->initiated_by ?? $case->user_id, 'action' => 'submitted', 'model_name' => 'TaxCase',
                    'new_values' => json_encode(['notification_log_id' => $log->id, 'attempt' => $attempt->attempt_number,
                        'stage_id' => $log->stage_id, 'transport_call_completed' => true]), 'performed_at' => now()]);
            } catch (\Throwable) { /* Notification evidence remains authoritative. */
            }
        } catch (\Throwable) {
            // No raw provider exception is persisted, returned, or rethrown to failed-job storage.
            // If persistence itself is unavailable the prior durable state remains non-retryable.
            try {
                $this->finish($attempt, $token, $boundary ? NotificationLog::UNCERTAIN : NotificationLog::PRE_SEND_FAILED,
                    $boundary ? 'TRANSPORT_OUTCOME_UNCERTAIN' : 'PRE_SEND_PREPARATION_FAILED');
            } catch (\Throwable) { /* PROCESSING/UNCERTAIN remains fail-closed; investigate operationally. */
            }
        }
    }

    private function enterBoundary(NotificationAttempt $attempt, string $token): bool
    {
        return DB::transaction(function () use ($attempt, $token) {
            $log = $attempt->notificationLog;
            // Match lifecycle/Revision writer ordering: case, log, attempt. Release
            // every lock before mail I/O; this is the final local eligibility check.
            $case = TaxCase::whereKey($log->tax_case_id)->lockForUpdate()->firstOrFail();
            if ($reason = app(KianNotificationService::class)->suppression($case, $log->stage_id, $log->event_snapshot)) {
                $this->finish($attempt, $token, NotificationLog::SUPPRESSED, $reason);

                return false;
            }

            return $this->transition($attempt, $token, NotificationLog::UNCERTAIN,
                ['send_boundary_at' => now(), 'reason' => 'TRANSPORT_OUTCOME_UNCERTAIN']);
        });
    }

    public function claim(string $token): ?NotificationAttempt
    {
        $candidate = NotificationAttempt::find($this->notificationAttemptId);
        if (! $candidate) {
            return null;
        }

        return DB::transaction(function () use ($candidate, $token) {
            $log = NotificationLog::whereKey($candidate->notification_log_id)->lockForUpdate()->first();
            if (! $log || $log->current_status !== NotificationLog::QUEUED || (int) $log->total_attempts !== (int) $candidate->attempt_number) {
                return null;
            }
            $changed = NotificationAttempt::whereKey($candidate->id)->where('status', NotificationLog::QUEUED)->whereNull('claim_token')
                ->update(['status' => NotificationLog::PROCESSING, 'claim_token' => $token, 'started_at' => now()]);
            if ($changed !== 1) {
                return null;
            }
            $log->update(['current_status' => NotificationLog::PROCESSING]);

            return $candidate->fresh('notificationLog');
        });
    }

    private function finish(NotificationAttempt $attempt, string $token, string $status, string $reason): void
    {
        $this->transition($attempt, $token, $status, ['reason' => $reason, 'finished_at' => now()]);
    }

    private function transition(NotificationAttempt $attempt, string $token, string $status, array $data): bool
    {
        return DB::transaction(function () use ($attempt, $token, $status, $data) {
            $log = NotificationLog::whereKey($attempt->notification_log_id)->lockForUpdate()->firstOrFail();
            $current = NotificationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($current->claim_token !== $token || (int) $log->total_attempts !== (int) $current->attempt_number
                || ! in_array($current->status, [NotificationLog::PROCESSING, NotificationLog::UNCERTAIN], true)) {
                return false;
            }
            // Once entered, no path can ever downgrade uncertainty into a pre-send failure.
            if ($current->send_boundary_at && ! in_array($status, [NotificationLog::SENT, NotificationLog::UNCERTAIN], true)) {
                return false;
            }
            $current->update($data + ['status' => $status]);
            $log->update(['current_status' => $status, 'last_attempt_at' => now()] + ($status === NotificationLog::SENT ? ['sent_at' => now()] : []));

            return true;
        });
    }
}
