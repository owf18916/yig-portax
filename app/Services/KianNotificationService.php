<?php

namespace App\Services;

use App\Jobs\SendKianReminderJob;
use App\Models\NotificationAttempt;
use App\Models\NotificationLog;
use App\Models\Revision;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class KianNotificationService
{
    private const RELATIONS = [4 => 'skpRecord', 7 => 'objectionDecision', 10 => 'appealDecision', 12 => 'supremeCourtDecision'];

    /** Identity is evidence identity, never an amount/prose hash. */
    public function event(TaxCase $case, int $stage, ?Revision $applying = null): ?array
    {
        if (! isset(self::RELATIONS[$stage])) {
            return null;
        }
        $target = $case->{self::RELATIONS[$stage]};
        if (! $target) {
            return null;
        }
        $history = $case->workflowHistories()->where('stage_id', $stage)
            ->whereIn('status', ['submitted', 'approved', 'completed'])->oldest('id')->first();
        if (! $history) {
            return null;
        } // no trustworthy committed submission provenance
        $revision = $applying ?? Revision::where('target_type', $target::class)->where('target_id', $target->id)
            ->where('stage_code', $stage)->where('revision_status', 'approved')->latest('id')->first();
        if ($revision && ((int) $revision->target_id !== (int) $target->id || $revision->target_type !== $target::class
            || (int) $revision->stage_code !== $stage || (int) $revision->revisable_id !== (int) $case->id)) {
            return null;
        }

        return ['key' => implode(':', ['kian-v1', $case->id, $stage, $target->getTable(), $target->id, $history->id, $revision?->id ?? 0]),
            'target_type' => $target::class, 'target_id' => $target->id, 'submission_history_id' => $history->id, 'revision_id' => $revision?->id];
    }

    public function suppression(TaxCase $case, int $stage, ?array $event, ?Revision $applying = null): ?string
    {
        if (! $event || ($this->event($case, $stage, $applying)['key'] ?? null) !== $event['key']) {
            return 'EVENT_SUPERSEDED_OR_UNPROVEN';
        }
        if (isset($event['entity_id']) && (int) $event['entity_id'] !== (int) $case->entity_id) {
            return 'CASE_ENTITY_CHANGED';
        }
        $evaluation = $case->exposureByStage()[$stage];
        if ($evaluation['evaluationState'] !== 'resolved' || ! $evaluation['needsKian']) {
            return 'EXPOSURE_NOT_POSITIVE';
        }
        if (isset($event['exposure_amount']) && $evaluation['remainingExposure'] !== $event['exposure_amount']) {
            return 'EXPOSURE_CHANGED';
        }
        if ($case->status?->code === 'CLOSED') {
            return 'CASE_CLOSED';
        }
        if ($case->kianSubmissions()->withTrashed()->where(fn ($q) => $q->where('stage_id', $stage)->orWhereNull('stage_id'))
            ->where(fn ($q) => $q->whereNotIn('status', ['draft'])->orWhereNull('status'))->exists()) {
            return 'KIAN_ALREADY_SUBMITTED_OR_INELIGIBLE';
        }

        return null;
    }

    public function evaluateFinalSubmit(TaxCase $taxCase, int $stageId, ?Revision $applying = null): ?NotificationLog
    {
        if (! isset(self::RELATIONS[$stageId])) {
            return null;
        }
        // Caller supplies the concrete Revision being applied in its enclosing transaction.
        $case = $taxCase->fresh();
        $event = $this->event($case, $stageId, $applying);
        if (! $event) {
            return null;
        }
        $evaluation = $case->exposureByStage()[$stageId];
        if ($evaluation['evaluationState'] !== 'resolved') {
            return null;
        }
        $eligible = $evaluation['needsKian'];
        $event += ['stage_id' => $stageId, 'exposure_amount' => $evaluation['remainingExposure'], 'entity_id' => $case->entity_id, 'case_owner_id' => $case->user_id,
            'case_number' => $case->case_number, 'reason' => "Stage {$stageId} remaining company tax exposure: {$evaluation['remainingExposure']}"];
        // Pending Revision is validated by the worker only after the outer commit.
        if ($eligible && $this->suppression($case, $stageId, $event, $applying)) {
            return null;
        }

        return DB::transaction(function () use ($case, $stageId, $event, $eligible) {
            $log = NotificationLog::firstOrCreate(['idempotency_key' => hash('sha256', $event['key'])], [
                'tax_case_id' => $case->id, 'entity_id' => $case->entity_id, 'stage_id' => $stageId,
                'notification_type' => NotificationLog::TYPE_KIAN_REMINDER, 'current_status' => NotificationLog::NOT_ELIGIBLE,
                'reason' => $eligible ? $event['reason'] : 'Remaining company tax exposure is zero.',
            ]);
            $log = NotificationLog::whereKey($log->id)->lockForUpdate()->firstOrFail();
            // Re-evaluation never retries, including safe failures and no-recipient results.
            if (! $log->wasRecentlyCreated && ($log->event_snapshot !== null || $log->total_attempts > 0)) {
                return $log;
            }
            $log->event_snapshot = $event;
            $log->save();
            if ($eligible) {
                $this->queueAttempt($log, $this->message($case, $event));
            }

            return $log;
        });
    }

    private function message(TaxCase $case, array $event): array
    {
        $values = ['caseNumber' => $case->case_number, 'entityName' => $case->entity?->name,
            'caseType' => $case->case_type, 'taxYear' => $case->fiscalYear?->year,
            'stageName' => \App\Services\TaxWorkflowMatrix\StageDefinitions::STAGES[$event['stage_id']]['label'] ?? 'Decision stage',
            'stage' => $event['stage_id'], 'amount' => $event['exposure_amount'], 'currency' => $case->currency?->code ?? 'IDR',
            'caseUrl' => url('/tax-cases/'.$case->id), 'reason' => $event['reason']];

        return ['event' => $event, 'values' => $values, 'subject' => "KIAN Required: Tax Case {$case->case_number} - Stage {$event['stage_id']}",
            'html' => view('emails.kian-reminder-snapshot', $values)->render(),
            'text' => html_entity_decode(view('emails.kian-reminder-snapshot-text', $values)->render(), ENT_QUOTES, 'UTF-8')];
    }

    public function activeActor(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->role !== null && $user->role->is_active;
    }

    public function canRetry(NotificationLog $log, ?User $user): bool
    {
        $last = $log->latestAttempt;

        return $this->activeActor($user) && $user->hasPermission('retry_notifications')
            && $log->taxCase !== null && Gate::forUser($user)->allows('view', $log->taxCase)
            && in_array($log->current_status, [NotificationLog::PRE_SEND_FAILED, NotificationLog::NO_VALID_TO], true)
            && $log->event_snapshot !== null && $last !== null && (int) $last->attempt_number === (int) $log->total_attempts
            && $last->status === $log->current_status && $last->send_boundary_at === null
            && $last->message_snapshot !== null;
    }

    public function retry(NotificationLog $log, User $user): NotificationAttempt
    {
        return DB::transaction(function () use ($log, $user) {
            $actor = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $actor->setRelation('role', $actor->role()->lockForUpdate()->first());
            $actor->load('entity');
            abort_unless($this->activeActor($actor) && $actor->hasPermission('retry_notifications'), 403);
            $case = TaxCase::whereKey($log->tax_case_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $case);
            $log = NotificationLog::whereKey($log->id)->where('tax_case_id', $case->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canRetry($log, $actor), 409, 'This notification is not safely retryable.');
            abort_if($this->suppression($case, $log->stage_id, $log->event_snapshot), 409, 'This reminder is no longer current or eligible.');

            return $this->queueAttempt($log, $log->latestAttempt->message_snapshot, $actor);
        });
    }

    private function queueAttempt(NotificationLog $log, array $message, ?User $actor = null): NotificationAttempt
    {
        $number = $log->total_attempts + 1;
        $log->update(['current_status' => NotificationLog::QUEUED, 'total_attempts' => $number,
            'first_queued_at' => $log->first_queued_at ?? now(), 'last_attempt_at' => now()]);
        $attempt = $log->attempts()->create(['attempt_number' => $number, 'status' => NotificationLog::QUEUED,
            'reason' => $log->reason, 'message_snapshot' => $message, 'to_recipients' => [], 'cc_recipients' => [], 'bcc_recipients' => [],
            'initiated_type' => $actor ? 'ADMIN_RETRY' : 'SYSTEM', 'initiated_by' => $actor?->id, 'queued_at' => now()]);
        SendKianReminderJob::dispatch($attempt->id)->afterCommit();

        return $attempt;
    }

    /** At most four stage rows and one latest attempt per stage. */
    public function summary(TaxCase $case, ?User $actor): array
    {
        $actor = $actor ? User::with(['role', 'entity'])->find($actor->id) : null;
        if (! $this->activeActor($actor) || ! Gate::forUser($actor)->allows('view', $case)) {
            return [];
        }
        $ids = NotificationLog::selectRaw('MAX(id)')->where('tax_case_id', $case->id)->whereIn('stage_id', [4, 7, 10, 12])->groupBy('stage_id');

        return NotificationLog::whereIn('id', $ids)->with(['latestAttempt', 'taxCase'])->orderBy('stage_id')->get()
            ->mapWithKeys(function ($log) use ($actor) {
                $data = $log->toArray();
                unset($data['latest_attempt'], $data['tax_case']);
                $data['can_retry'] = $this->canRetry($log, $actor);
                $data['attempts'] = $log->latestAttempt ? [$log->latestAttempt->toArray()] : [];

                return [$log->stage_id => $data];
            })->all();
    }
}
