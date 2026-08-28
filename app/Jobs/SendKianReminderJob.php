<?php
namespace App\Jobs;

use App\Mail\KianReminderMail;
use App\Models\NotificationAttempt;
use App\Models\NotificationLog;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendKianReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public function __construct(public int $notificationAttemptId) {}
    public function handle(): void
    {
        $attempt=NotificationAttempt::with('notificationLog')->findOrFail($this->notificationAttemptId); $log=$attempt->notificationLog;
        if ($attempt->status !== NotificationLog::QUEUED) return;
        $attempt->update(['status'=>NotificationLog::PROCESSING,'started_at'=>now()]); $log->update(['current_status'=>NotificationLog::PROCESSING]);
        try {
            $case=TaxCase::with(['entity.parentEntity','user'])->findOrFail($log->tax_case_id); [$to,$cc]=$this->recipients($case);
            $attempt->update(['to_recipients'=>$to,'cc_recipients'=>$cc,'bcc_recipients'=>[]]);
            if (!$to) { $this->finish($attempt,$log,NotificationLog::SKIPPED_NO_RECIPIENT,'No valid TO recipient is currently configured.'); return; }
            Mail::to($to)->cc($cc)->send(new KianReminderMail($case->id,$this->stageName($log->stage_id),$log->reason ?? '',$log->stage_id));
            $this->finish($attempt,$log,NotificationLog::SENT);
            try { \App\Models\AuditLog::create(['auditable_type'=>TaxCase::class,'auditable_id'=>$case->id,'user_id'=>$case->user_id,'action'=>'submitted','model_name'=>'TaxCase','new_values'=>json_encode(['notification_log_id'=>$log->id,'attempt'=>$attempt->attempt_number,'stage_id'=>$log->stage_id]),'performed_at'=>now()]); } catch (\Throwable) { /* delivery state remains authoritative */ }
        } catch (\Throwable $e) { $this->finish($attempt,$log,NotificationLog::FAILED,null,mb_substr($e->getMessage(),0,1000)); throw $e; }
    }
    private function recipients(TaxCase $case): array {
        $to=[]; if(filter_var($case->user?->email,FILTER_VALIDATE_EMAIL))$to[]=$case->user->email;
        if($case->user?->entity_id)$to=array_merge($to,User::where('entity_id',$case->user->entity_id)->whereHas('role',fn($q)=>$q->where('name','Manager'))->pluck('email')->all());
        $holding=$case->entity?->entity_type==='AFFILIATE'?$case->entity?->parentEntity:$case->entity;
        $cc=$holding?User::where('entity_id',$holding->id)->whereHas('role',fn($q)=>$q->whereIn('name',['Coordinator','General Manager','Vice President']))->pluck('email')->all():[];
        $valid=fn($emails)=>array_values(array_unique(array_filter($emails,fn($email)=>filter_var($email,FILTER_VALIDATE_EMAIL)))); $to=$valid($to); return [$to,array_values(array_diff($valid($cc),$to))];
    }
    private function finish(NotificationAttempt $attempt, NotificationLog $log, string $status, ?string $reason=null, ?string $error=null): void { $now=now();$attempt->update(['status'=>$status,'reason'=>$reason??$attempt->reason,'error_message'=>$error,'finished_at'=>$now]);$data=['current_status'=>$status,'last_attempt_at'=>$now];if($status===NotificationLog::SENT)$data['sent_at']=$now;$log->update($data); }
    private function stageName(int $stage): string { return [4=>'Stage 4 - SKP (Surat Ketetapan Pajak)',7=>'Stage 7 - Objection Decision (Keputusan Keberatan)',10=>'Stage 10 - Appeal Decision (Keputusan Banding)',12=>'Stage 12 - Supreme Court Decision (Keputusan Peninjauan Kembali)'][$stage]??"Stage $stage"; }
}
