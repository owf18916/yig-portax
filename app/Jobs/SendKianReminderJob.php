<?php

namespace App\Jobs;

use App\Mail\KianReminderMail;
use App\Models\TaxCase;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendKianReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $taxCaseId;
    protected string $stageName;
    protected string $reason;
    protected int $stageId;

    /**
     * Create a new job instance.
     * 
     * @param int $taxCaseId The tax case ID
     * @param string $stageName Human-readable stage name (e.g., "Stage 4 - SKP")
     * @param string $reason Eligibility reason for KIAN
     * @param int $stageId The stage ID that triggered KIAN (4, 7, 10, 12)
     */
    public function __construct(int $taxCaseId, string $stageName, string $reason, int $stageId = 12)
    {
        $this->taxCaseId = $taxCaseId;
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
            $case = TaxCase::with(['entity', 'entity.parentEntity', 'user', 'user.role'])->find($this->taxCaseId);
            
            if (!$case) {
                Log::warning('Tax case not found for KIAN reminder', [
                    'tax_case_id' => $this->taxCaseId,
                    'stage' => $this->stageName,
                ]);
                return;
            }

            // ⭐ BUILD TO RECIPIENTS:
            // 1. Case owner
            // 2. Case owner's manager (same entity, role "Manager")
            $toEmails = [];
            
            // Add case owner (always included)
            $caseOwner = $case->user;
            if ($caseOwner && $caseOwner->email) {
                $toEmails[] = $caseOwner->email;
            }
            
            // Add case owner's manager(s) from same entity
            $caseOwnerEntityId = $caseOwner?->entity_id;
            if ($caseOwnerEntityId) {
                $managers = User::where('entity_id', $caseOwnerEntityId)
                    ->whereHas('role', function ($query) {
                        $query->where('name', 'Manager');
                    })
                    ->pluck('email')
                    ->toArray();
                
                $toEmails = array_merge($toEmails, $managers);
            }

            // ⭐ BUILD CC RECIPIENTS:
            // Users from Holding Affiliates with roles: "Coordinator", "General Manager", "Vice President"
            $ccEmails = [];
            
            // Get holding entity (parent of current entity if AFFILIATE, or self if HOLDING)
            $holdingEntity = null;
            if ($case->entity->entity_type === 'AFFILIATE') {
                $holdingEntity = $case->entity->parentEntity;
            } elseif ($case->entity->entity_type === 'HOLDING') {
                $holdingEntity = $case->entity;
            }
            
            // Get all users from holding with specific roles
            if ($holdingEntity) {
                $ccEmails = User::where('entity_id', $holdingEntity->id)
                    ->whereHas('role', function ($query) {
                        $query->whereIn('name', ['Coordinator', 'General Manager', 'Vice President']);
                    })
                    ->pluck('email')
                    ->toArray();
            }

            // ⭐ Deduplicate recipients (remove duplicates between TO and CC)
            $toEmails = array_unique(array_filter($toEmails));
            $ccEmails = array_unique(array_filter($ccEmails));
            
            // Remove CC emails that are already in TO
            $ccEmails = array_values(array_diff($ccEmails, $toEmails));

            // Send email
            if (!empty($toEmails)) {
                Mail::to($toEmails)
                    ->cc($ccEmails)
                    ->send(new KianReminderMail($this->taxCaseId, $this->stageName, $this->reason, $this->stageId));
                
                $sentTo = $toEmails;
            } else {
                Log::warning('No TO recipients found for KIAN reminder', [
                    'tax_case_id' => $this->taxCaseId,
                    'stage' => $this->stageName,
                    'case_owner_id' => $caseOwner?->id,
                ]);
                return;
            }

            // Log to audit_logs with complete recipient information
            // Wrap in try-catch since custom action values might not be in enum
            $allRecipients = array_merge($sentTo, $ccEmails);
            $allRecipients = array_unique($allRecipients);
            
            try {
                \App\Models\AuditLog::create([
                    'auditable_type' => TaxCase::class,
                    'auditable_id' => $this->taxCaseId,
                    'user_id' => $case->user_id,
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
                    'tax_case_id' => $this->taxCaseId,
                    'stage' => $this->stageName,
                    'audit_error' => $auditError->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send KIAN reminder', [
                'tax_case_id' => $this->taxCaseId,
                'stage' => $this->stageName,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
