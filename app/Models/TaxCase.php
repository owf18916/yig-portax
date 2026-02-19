<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxCase extends Model
{
    use SoftDeletes;

    protected $table = 'tax_cases';

    protected $fillable = [
        'user_id',
        'entity_id',
        'fiscal_year_id',
        'period_id',
        'currency_id',
        'case_status_id',
        'case_number',
        'case_type',
        'spt_number',
        'spt_type',
        'filing_date',
        'received_date',
        'reported_amount',
        'disputed_amount',
        'vat_in_amount',
        'vat_out_amount',
        'current_stage',
        'is_completed',
        'completed_date',
        'description',
        'refund_amount',
        'refund_date',
        'next_action',
        'next_action_due_date',
        'status_comment',
    ];

    protected $casts = [
        'reported_amount' => 'decimal:2',
        'disputed_amount' => 'decimal:2',
        'vat_in_amount' => 'decimal:2',
        'vat_out_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'current_stage' => 'integer',
        'is_completed' => 'boolean',
        'filing_date' => 'date',
        'received_date' => 'date',
        'completed_date' => 'date',
        'refund_date' => 'date',
        'next_action_due_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = ['entity_name'];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(CaseStatus::class, 'case_status_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Stage relationships
    public function sp2Record(): HasOne
    {
        return $this->hasOne(Sp2Record::class);
    }

    public function sphpRecord(): HasOne
    {
        return $this->hasOne(SphpRecord::class);
    }

    public function skpRecord(): HasOne
    {
        return $this->hasOne(SkpRecord::class);
    }

    public function objectionSubmission(): HasOne
    {
        return $this->hasOne(ObjectionSubmission::class);
    }

    public function spuhRecord(): HasOne
    {
        return $this->hasOne(SpuhRecord::class);
    }

    public function objectionDecision(): HasOne
    {
        return $this->hasOne(ObjectionDecision::class);
    }

    public function appealSubmission(): HasOne
    {
        return $this->hasOne(AppealSubmission::class);
    }

    public function appealExplanationRequest(): HasOne
    {
        return $this->hasOne(AppealExplanationRequest::class);
    }

    public function appealDecision(): HasOne
    {
        return $this->hasOne(AppealDecision::class);
    }

    // Revision relationship
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'revisable_id')->where('revisable_type', 'TaxCase');
    }

    public function lastRevision(): BelongsTo
    {
        return $this->belongsTo(Revision::class, 'last_revision_id');
    }

    public function supremeCourtSubmission(): HasOne
    {
        return $this->hasOne(SupremeCourtSubmission::class);
    }

    public function supremeCourtDecision(): HasOne
    {
        return $this->hasOne(SupremeCourtDecision::class);
    }

    public function kianSubmission(): HasOne
    {
        return $this->hasOne(KianSubmission::class);
    }

    public function kianSubmissions(): HasMany
    {
        return $this->hasMany(KianSubmission::class);
    }

    /**
     * Preliminary refund request (one-to-one relationship)
     */
    public function preliminaryRefundRequest(): HasOne
    {
        return $this->hasOne(PreliminaryRefundRequest::class);
    }

    /**
     * One tax case can have multiple refund processes
     * Changed from HasOne to HasMany to support multiple refunds
     */
    public function refundProcesses(): HasMany
    {
        return $this->hasMany(RefundProcess::class)->orderBy('sequence_number', 'asc');
    }

    /**
     * Get the latest refund process for this tax case
     * 
     * @return RefundProcess|null
     */
    public function latestRefund()
    {
        return $this->refundProcesses()->latestForTaxCase()->first();
    }

    /**
     * Check if a new refund can be created
     * A refund can be created if the total refunded amount is less than the disputed amount
     * 
     * @return bool
     */
    public function canCreateRefund(): bool
    {
        $totalRefunded = $this->getTotalRefundedAmount();
        return $totalRefunded < $this->disputed_amount;
    }

    /**
     * Get total amount already refunded across all refund processes
     * 
     * @return float
     */
    public function getTotalRefundedAmount(): float
    {
        return (float) $this->refundProcesses()->sum('refund_amount');
    }

    /**
     * Get available amount that can still be refunded
     * This is the disputed amount minus what has already been refunded
     * 
     * @return float
     */
    public function getAvailableRefundAmount(): float
    {
        return max(0, (float) $this->disputed_amount - $this->getTotalRefundedAmount());
    }

    /**
     * Check if this is a Pengembalian Pendahuluan (Preliminary Refund) case
     * 
     * @return bool
     */
    public function isPengembalianPendahuluan(): bool
    {
        return $this->spt_type === 'Pengembalian Pendahuluan';
    }

    /**
     * Check if case should skip audit stages (SP2 and SPHP)
     * Only Pengembalian Pendahuluan cases skip these stages
     * 
     * @return bool
     */
    public function shouldSkipAuditStages(): bool
    {
        return $this->isPengembalianPendahuluan();
    }

    /**
     * Get available stages for this tax case based on SPT type
     * 
     * @return array Stage IDs available for this case
     */
    public function getAvailableStages(): array
    {
        if ($this->isPengembalianPendahuluan()) {
            // Pengembalian Pendahuluan: Skip SP2 (2) and SPHP (3), go directly from SPT (1) to SKP (4)
            return [1, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
        }

        // Default: All stages available
        return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
    }

    /**
     * Check if a specific stage is available for this case
     * 
     * @param int $stageId
     * @return bool
     */
    public function isStageAvailable(int $stageId): bool
    {
        return in_array($stageId, $this->getAvailableStages());
    }

    /**
     * CHANGE 4: Check if tax case is eligible for KIAN (Internal Loss Recognition)
     */
    public function canCreateKian(): bool
    {
        // Must not already have KIAN submission
        if ($this->kianSubmissions()->exists()) {
            return false;
        }

        return $this->needsKianReminder();
    }

    /**
     * CHANGE 4: Check if KIAN reminder should be sent
     */
    public function needsKianReminder(): bool
    {
        // Check each stage for KIAN eligibility conditions
        
        // Check SKP Stage (Stage 4)
        $skpRecord = $this->skpRecord;
        if ($skpRecord) {
            if ($skpRecord->skp_amount < $this->disputed_amount && !$skpRecord->continue_to_next_stage) {
                return true;
            }
        }

        // Check Preliminary Refund (if Pengembalian Pendahuluan case)
        $preliminaryRefund = $this->preliminaryRefundRequest;
        if ($preliminaryRefund && $preliminaryRefund->approval_status === 'REJECTED') {
            return true;
        }

        // Check Objection Decision (Stage 7)
        $objectionDecision = $this->objectionDecision;
        if ($objectionDecision) {
            if ($objectionDecision->decision_amount < $this->disputed_amount && !$objectionDecision->continue_to_next_stage) {
                return true;
            }
        }

        // Check Appeal Decision (Stage 10)
        $appealDecision = $this->appealDecision;
        if ($appealDecision) {
            if ($appealDecision->decision_amount < $this->disputed_amount && !$appealDecision->continue_to_next_stage) {
                return true;
            }
        }

        // Check Supreme Court Decision (Stage 12) - Final stage
        $supremeCourtDecision = $this->supremeCourtDecision;
        if ($supremeCourtDecision) {
            if (in_array($supremeCourtDecision->decision_type, ['PARTIALLY_GRANTED', 'REJECTED'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * CHANGE 4: Get human-readable reason why KIAN is needed
     */
    public function getKianEligibilityReason(): ?string
    {
        $reasons = [];

        // Check SKP Stage
        $skpRecord = $this->skpRecord;
        if ($skpRecord) {
            if ($skpRecord->skp_amount < $this->disputed_amount && !$skpRecord->continue_to_next_stage) {
                $loss = $this->disputed_amount - $skpRecord->skp_amount;
                $reasons[] = "SKP Amount (Rp " . number_format($skpRecord->skp_amount, 0, ',', '.') . ") is less than disputed amount. Loss: Rp " . number_format($loss, 0, ',', '.');
            }
        }

        // Check Preliminary Refund
        $preliminaryRefund = $this->preliminaryRefundRequest;
        if ($preliminaryRefund && $preliminaryRefund->approval_status === 'REJECTED') {
            $reasons[] = "Preliminary Refund Request was REJECTED. Amount requested: Rp " . number_format($preliminaryRefund->requested_amount, 0, ',', '.');
        }

        // Check Objection Decision
        $objectionDecision = $this->objectionDecision;
        if ($objectionDecision) {
            if ($objectionDecision->decision_amount < $this->disputed_amount && !$objectionDecision->continue_to_next_stage) {
                $loss = $this->disputed_amount - $objectionDecision->decision_amount;
                $reasons[] = "Objection Decision (Rp " . number_format($objectionDecision->decision_amount, 0, ',', '.') . ") is less than disputed amount. Loss: Rp " . number_format($loss, 0, ',', '.');
            }
        }

        // Check Appeal Decision
        $appealDecision = $this->appealDecision;
        if ($appealDecision) {
            if ($appealDecision->decision_amount < $this->disputed_amount && !$appealDecision->continue_to_next_stage) {
                $loss = $this->disputed_amount - $appealDecision->decision_amount;
                $reasons[] = "Appeal Decision (Rp " . number_format($appealDecision->decision_amount, 0, ',', '.') . ") is less than disputed amount. Loss: Rp " . number_format($loss, 0, ',', '.');
            }
        }

        // Check Supreme Court Decision
        $supremeCourtDecision = $this->supremeCourtDecision;
        if ($supremeCourtDecision) {
            if (in_array($supremeCourtDecision->decision_type, ['PARTIALLY_GRANTED', 'REJECTED'])) {
                $loss = $this->disputed_amount - ($supremeCourtDecision->decision_amount ?? 0);
                $reasons[] = "Supreme Court Decision type: {$supremeCourtDecision->decision_type}. Loss: Rp " . number_format($loss, 0, ',', '.');
            }
        }

        return !empty($reasons) ? implode(' | ', $reasons) : null;
    }

    /**
     * ✅ NEW METHOD 1: Check if KIAN is needed at a specific stage
     * Multiple KIAN per stage concept (v2)
     */
    public function needsKianAtStage(int $stageId): bool
    {
        if ($stageId === 4) {
            // Stage 4: SKP
            return $this->skpRecord && 
                   $this->skpRecord->skp_amount < $this->disputed_amount;
        }
        
        if ($stageId === 7) {
            // Stage 7: Objection Decision
            return $this->objectionDecision && $this->objectionSubmission &&
                   $this->objectionDecision->decision_amount < 
                   $this->objectionSubmission->objection_amount;
        }
        
        if ($stageId === 10) {
            // Stage 10: Appeal Decision
            return $this->appealDecision && $this->appealSubmission &&
                   $this->appealDecision->decision_amount < 
                   $this->appealSubmission->appeal_amount;
        }
        
        if ($stageId === 12) {
            // Stage 12: Supreme Court Decision
            return $this->supremeCourtDecision && $this->supremeCourtSubmission &&
                   in_array($this->supremeCourtDecision->decision_type, 
                       ['PARTIALLY_GRANTED', 'REJECTED']) &&
                   $this->supremeCourtDecision->decision_amount < 
                   $this->supremeCourtSubmission->review_amount;
        }
        
        return false;
    }

    /**
     * ✅ NEW METHOD 2: Calculate loss amount at a specific stage
     * Returns null if no loss at that stage
     */
    public function calculateLossAtStage(int $stageId): ?float
    {
        if ($stageId === 4) {
            if (!$this->needsKianAtStage(4)) return null;
            return (float) ($this->disputed_amount - $this->skpRecord->skp_amount);
        }
        
        if ($stageId === 7) {
            if (!$this->needsKianAtStage(7)) return null;
            return (float) ($this->objectionSubmission->objection_amount - 
                   $this->objectionDecision->decision_amount);
        }
        
        if ($stageId === 10) {
            if (!$this->needsKianAtStage(10)) return null;
            return (float) ($this->appealSubmission->appeal_amount - 
                   $this->appealDecision->decision_amount);
        }
        
        if ($stageId === 12) {
            if (!$this->needsKianAtStage(12)) return null;
            return (float) ($this->supremeCourtSubmission->review_amount - 
                   $this->supremeCourtDecision->decision_amount);
        }
        
        return null;
    }

    /**
     * ✅ NEW METHOD 3: Check if KIAN can be created for a specific stage
     * Returns true only if:
     * - KIAN is needed at that stage
     * - KIAN for that stage doesn't already exist
     */
    public function canCreateKianForStage(int $stageId): bool
    {
        // KIAN must be needed at this stage
        if (!$this->needsKianAtStage($stageId)) {
            return false;
        }
        
        // KIAN for this stage must not already exist
        return !$this->kianSubmissions()
            ->where('stage_id', $stageId)
            ->exists();
    }

    /**
     * ✅ NEW METHOD 4: Get eligibility reason for KIAN at specific stage
     * Returns null if no KIAN needed at stage, otherwise returns human-readable reason
     */
    public function getKianEligibilityReasonForStage(int $stageId): ?string
    {
        $loss = $this->calculateLossAtStage($stageId);
        if (!$loss) return null;
        
        $lossFormatted = 'Rp ' . number_format($loss, 0, ',', '.');
        
        switch ($stageId) {
            case 4:
                return "SKP amount (Rp " . 
                    number_format($this->skpRecord->skp_amount, 0, ',', '.') . 
                    ") kurang dari SPT (Rp " . 
                    number_format($this->disputed_amount, 0, ',', '.') . 
                    "). Loss: {$lossFormatted}";
            
            case 7:
                return "Keputusan Keberatan (Rp " . 
                    number_format($this->objectionDecision->decision_amount, 0, ',', '.') . 
                    ") kurang dari pengajuan (Rp " . 
                    number_format($this->objectionSubmission->objection_amount, 0, ',', '.') . 
                    "). Loss: {$lossFormatted}";
            
            case 10:
                return "Keputusan Banding (Rp " . 
                    number_format($this->appealDecision->decision_amount, 0, ',', '.') . 
                    ") kurang dari pengajuan (Rp " . 
                    number_format($this->appealSubmission->appeal_amount, 0, ',', '.') . 
                    "). Loss: {$lossFormatted}";
            
            case 12:
                return "Keputusan PK (Rp " . 
                    number_format($this->supremeCourtDecision->decision_amount, 0, ',', '.') . 
                    ") kurang dari pengajuan (Rp " . 
                    number_format($this->supremeCourtSubmission->review_amount, 0, ',', '.') . 
                    "). Loss: {$lossFormatted}";
        }
        
        return null;
    }

    /**
     * ✅ NEW METHOD 5: Get comprehensive KIAN status for all 4 stages
     * Returns array with status for each stage (4, 7, 10, 12)
     * Used in API response for frontend
     */
    public function getKianStatusByStage(): array
    {
        $stages = [4, 7, 10, 12];
        $status = [];
        
        foreach ($stages as $stageId) {
            $needsKian = $this->needsKianAtStage($stageId);
            $lossAmount = $this->calculateLossAtStage($stageId);
            $reason = $this->getKianEligibilityReasonForStage($stageId);
            
            // Check if KIAN already submitted for this stage
            $kianSubmission = $this->kianSubmissions()
                ->where('stage_id', $stageId)
                ->first();
            
            $status[$stageId] = [
                'needsKian' => $needsKian,
                'lossAmount' => $lossAmount,
                'reason' => $reason,
                'submitted' => $kianSubmission ? in_array($kianSubmission->status, ['submitted', 'approved']) : false,
                'kianId' => $kianSubmission?->id,
                'kianStatus' => $kianSubmission?->status,
            ];
        }
        
        return $status;
    }

    // Audit relationships
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'auditable_id')
            ->where('auditable_type', self::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class);
    }

    public function workflowHistories(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class);
    }

    // Accessors
    public function getEntityNameAttribute()
    {
        return $this->entity?->name ?? 'Unknown Entity';
    }

    public function getStageStatusAttribute()
    {
        $latestHistory = $this->workflowHistories()
            ->where('stage_id', $this->current_stage)
            ->latest('created_at')
            ->first();
        
        return $latestHistory?->status ?? 'draft';
    }
}
