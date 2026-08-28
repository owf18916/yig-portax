<?php
namespace App\Services;

use App\Jobs\SendKianReminderJob;
use App\Models\NotificationAttempt;
use App\Models\NotificationLog;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class KianNotificationService
{
    public function evaluateFinalSubmit(TaxCase $taxCase, int $stageId): ?NotificationLog
    {
        if (!in_array($stageId, [4, 7, 10, 12], true)) return null;
        $taxCase->loadMissing(['skpRecord','objectionSubmission','objectionDecision','appealSubmission','appealDecision','supremeCourtSubmission','supremeCourtDecision']);
        $eligible = $taxCase->needsKianAtStage($stageId);
        $reason = $eligible ? $taxCase->getKianEligibilityReasonForStage($stageId) : $this->ineligibleReason($stageId);
        $key = hash('sha256', implode('|', [$taxCase->id, $stageId, NotificationLog::TYPE_KIAN_REMINDER, $reason]));
        // Unique key prevents concurrent double-clicks from creating two logical notifications.
        NotificationLog::firstOrCreate(['idempotency_key' => $key], [
            'tax_case_id'=>$taxCase->id, 'entity_id'=>$taxCase->entity_id, 'stage_id'=>$stageId,
            'notification_type'=>NotificationLog::TYPE_KIAN_REMINDER, 'current_status'=>NotificationLog::NOT_ELIGIBLE,
        ]);
        return DB::transaction(function () use ($taxCase, $stageId, $eligible, $reason, $key) {
            $log = NotificationLog::where('idempotency_key', $key)->lockForUpdate()->first();
            if (!$eligible) {
                if (!$log || $log->current_status !== NotificationLog::NOT_ELIGIBLE) {
                    $log = $log ?: new NotificationLog(['tax_case_id'=>$taxCase->id,'entity_id'=>$taxCase->entity_id,'stage_id'=>$stageId,'notification_type'=>NotificationLog::TYPE_KIAN_REMINDER,'idempotency_key'=>$key]);
                    $log->fill(['current_status'=>NotificationLog::NOT_ELIGIBLE,'total_attempts'=>0,'reason'=>$reason])->save();
                }
                return $log;
            }
            // Same stage and same eligibility reason is the idempotency key for the current data cycle.
            if ($log && in_array($log->current_status, [NotificationLog::QUEUED, NotificationLog::PROCESSING, NotificationLog::SENT], true) && $log->reason === $reason) return $log;
            $log = $log ?: new NotificationLog(['tax_case_id'=>$taxCase->id,'entity_id'=>$taxCase->entity_id,'stage_id'=>$stageId,'notification_type'=>NotificationLog::TYPE_KIAN_REMINDER,'idempotency_key'=>$key]);
            $number = ((int) $log->total_attempts) + 1;
            $now = now();
            $log->fill(['current_status'=>NotificationLog::QUEUED,'total_attempts'=>$number,'reason'=>$reason,'first_queued_at'=>$log->first_queued_at ?: $now,'last_attempt_at'=>$now])->save();
            $attempt = $log->attempts()->create(['attempt_number'=>$number,'status'=>NotificationLog::QUEUED,'reason'=>$reason,'to_recipients'=>[],'cc_recipients'=>[],'bcc_recipients'=>[],'initiated_type'=>'SYSTEM','queued_at'=>$now]);
            SendKianReminderJob::dispatch($attempt->id)->afterCommit();
            return $log;
        });
    }

    public function retry(NotificationLog $log, User $user): NotificationAttempt
    {
        if (!in_array($log->current_status, [NotificationLog::SKIPPED_NO_RECIPIENT, NotificationLog::FAILED], true)) abort(422, 'Only failed or no-recipient notifications can be retried.');
        return DB::transaction(function () use ($log, $user) {
            $log = NotificationLog::lockForUpdate()->findOrFail($log->id);
            if (!in_array($log->current_status, [NotificationLog::SKIPPED_NO_RECIPIENT, NotificationLog::FAILED], true)) abort(409, 'This notification is already being processed.');
            $now=now(); $number=$log->total_attempts + 1;
            $log->update(['current_status'=>NotificationLog::QUEUED,'total_attempts'=>$number,'last_attempt_at'=>$now]);
            $attempt=$log->attempts()->create(['attempt_number'=>$number,'status'=>NotificationLog::QUEUED,'reason'=>$log->reason,'to_recipients'=>[],'cc_recipients'=>[],'bcc_recipients'=>[],'initiated_type'=>'ADMIN_RETRY','initiated_by'=>$user->id,'queued_at'=>$now]);
            SendKianReminderJob::dispatch($attempt->id)->afterCommit();
            return $attempt;
        });
    }
    private function ineligibleReason(int $stage): string { return $stage === 4 ? 'SKP amount is not lower than disputed amount.' : 'The stage-specific KIAN eligibility rule was not met.'; }
}
