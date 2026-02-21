<?php

namespace App\Jobs;

use App\Models\TaxCase;
use Illuminate\Bus\Queueable;
use App\Mail\KianReminderMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class SendKianReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected TaxCase $taxCase;
    protected string $stageName;
    protected string $reason;
    protected int $stageId;

    /**
     * Create a new job instance.
     * 
     * @param TaxCase $taxCase The tax case
     * @param string $stageName Human-readable stage name (e.g., "Stage 4 - SKP")
     * @param string $reason Eligibility reason for KIAN
     * @param int $stageId The stage ID that triggered KIAN (4, 7, 10, 12)
     */
    public function __construct(TaxCase $taxCase, string $stageName, string $reason, int $stageId = 12)
    {
        $this->taxCase = $taxCase;
        $this->stageName = $stageName;
        $this->reason = $reason;
        $this->stageId = $stageId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Get tax case with relationships
            $case = $this->taxCase->load(['entity', 'user']);

            // Get entity users (primary recipients)
            $entityUsers = $case->entity->users ?? collect();

            // Get holding users for CC (if applicable)
            $holdingUsers = $case->entity->holding?->users ?? collect();
            $ccEmails = $holdingUsers->pluck('email')->toArray();

            // ⭐ APPROACH 2: Send single email to ALL entity users (TO) with holding users (CC once)
            // This is more efficient and aligns with documentation:
            // "Send email to entity users with CC to holding users"
            if ($entityUsers->isNotEmpty()) {
                // All entity users as TO recipients
                $toEmails = $entityUsers->pluck('email')->toArray();
                
                Mail::to($toEmails)
                    ->cc($ccEmails)
                    ->send(new KianReminderMail($case, $this->stageName, $this->reason, $this->stageId));
                    
                $sentTo = $toEmails;
            } else {
                // Fallback: send to case owner if no entity users
                Mail::to($case->user->email)
                    ->cc($ccEmails)
                    ->send(new KianReminderMail($case, $this->stageName, $this->reason, $this->stageId));
                    
                $sentTo = [$case->user->email];
            }

            // Log to audit_logs with complete recipient information
            // Wrap in try-catch since custom action values might not be in enum
            $allRecipients = collect($sentTo)->merge($ccEmails)->unique()->values()->toArray();
            
            try {
                \App\Models\AuditLog::create([
                    'auditable_type' => TaxCase::class,
                    'auditable_id' => $this->taxCase->id,
                    'user_id' => $this->taxCase->user_id, // Use case owner since job runs in queue (no auth context)
                    'action' => 'submitted', // Use standard action value
                    'description' => "KIAN reminder sent for stage: {$this->stageName} (Stage {$this->stageId})",
                    'old_values' => null,
                    'new_values' => json_encode([
                        'stage_id' => $this->stageId,
                        'stage_name' => $this->stageName,
                        'reason' => $this->reason,
                        'to_recipients' => $sentTo,
                        'cc_recipients' => $ccEmails,
                        'total_recipients' => count($allRecipients),
                        'all_recipients' => $allRecipients,
                    ]),
                    'ip_address' => request()?->ip(),
                ]);
            } catch (\Exception $auditError) {
                // Log audit failure but don't fail the entire job
                Log::warning('Failed to create audit log for KIAN reminder', [
                    'tax_case_id' => $this->taxCase->id,
                    'stage' => $this->stageName,
                    'audit_error' => $auditError->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send KIAN reminder', [
                'tax_case_id' => $this->taxCase->id,
                'stage' => $this->stageName,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
