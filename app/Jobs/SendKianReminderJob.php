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

    /**
     * Create a new job instance.
     */
    public function __construct(TaxCase $taxCase, string $stageName, string $reason)
    {
        $this->taxCase = $taxCase;
        $this->stageName = $stageName;
        $this->reason = $reason;
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

            // Send email to entity users
            foreach ($entityUsers as $user) {
                Mail::to($user->email)
                    ->cc($holdingUsers->pluck('email')->toArray())
                    ->send(new KianReminderMail($case, $this->stageName, $this->reason));
            }

            // If no entity users, send to case owner
            if ($entityUsers->isEmpty()) {
                Mail::to($case->user->email)
                    ->cc($holdingUsers->pluck('email')->toArray())
                    ->send(new KianReminderMail($case, $this->stageName, $this->reason));
            }

            // Log to audit_logs
            \App\Models\AuditLog::create([
                'auditable_type' => TaxCase::class,
                'auditable_id' => $this->taxCase->id,
                'user_id' => auth()->id() ?? null,
                'action' => 'KIAN_REMINDER_SENT',
                'description' => "KIAN reminder sent for stage: {$this->stageName}",
                'old_values' => null,
                'new_values' => json_encode([
                    'stage' => $this->stageName,
                    'reason' => $this->reason,
                    'recipients' => $entityUsers->pluck('email')->merge($holdingUsers->pluck('email'))->unique()->values(),
                ]),
                'ip_address' => request()?->ip(),
            ]);
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
