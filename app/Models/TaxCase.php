<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * TaxCase Model
 * 
 * ✅ REFACTORED ARCHITECTURE (Feb 2026):
 * 
 * MAIN WORKFLOW STAGES: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 [TERMINAL]
 * 
 * INDEPENDENT PARALLEL PROCESSES (attach to decision stages):
 * - Refund: Can trigger from Stage 4, 7, 10, or 12 (if decision is favorable)
 *   └─ One refund per stage max (via unique constraint on tax_case_id + stage_id)
 * - KIAN: Can trigger from Stage 4, 7, 10, or 12 (if loss exists)
 *   └─ One KIAN per stage max (via unique constraint on tax_case_id + stage_id)
 * 
 * SUPPORTING EXECUTION STAGES (not part of main workflow):
 * - Stage 13-15: Bank Transfer execution (linked to specific RefundProcess, not TaxCase.current_stage)
 * - These are triggered AFTER RefundProcess is created by user choice
 * 
 * KEY DESIGN PATTERNS:
 * 1. current_stage ∈ {1-12} only (main workflow isolation)
 * 2. Refund/KIAN are user-initiated parallel processes
 * 3. Bank transfer is manual user input, NOT auto-generated
 * 4. Preliminary Refund (spt_type='Pengembalian Pendahuluan') is special case at Stage 1
 *    └─ Refund attach directly to Stage 1 with stage_id=0
 *    └─ "Lanjut SP2" button allows independent progress to Stage 2
 */
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
        'spt_position_type',
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
        return $this->hasMany(Revision::class, 'revisable_id')
            ->whereIn('revisable_type', ['TaxCase', self::class]);
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
     * ✅ REFACTORED: Main workflow is ONLY stages 1-12
     * - Stages 13/14/15 are SUPPORTING EXECUTION stages for refund (not main workflow)
     * - Refund/KIAN are INDEPENDENT PARALLEL PROCESSES (attach to decision stages)
     * - current_stage ∈ {1-12} only (main workflow)
     * 
     * @return array Stage IDs available for this case
     */
    public function getAvailableStages(): array
    {
        // Main workflow is always 1-12, regardless of case type
        // Refund/KIAN are independent, not part of sequential stages
        $mainWorkflowStages = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

        if ($this->isPengembalianPendahuluan()) {
            // Pengembalian Pendahuluan: Skip SP2 (2) and SPHP (3)
            // Go directly: 1 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12
            return [1, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        }

        // Default: All main workflow stages available
        return $mainWorkflowStages;
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
        foreach ($this->getKianStatusByStage() as $result) {
            if ($result['needsKian'] === true && !$result['kianId']) return true;
        }
        return false;
    }

    /**
     * CHANGE 4: Check if KIAN reminder should be sent
     */
    public function needsKianReminder(): bool
    {
        foreach ($this->exposureByStage() as $result) {
            if ($result['needsKian'] === true) return true;
        }
        return false;
    }

    /**
     * CHANGE 4: Get human-readable reason why KIAN is needed
     */
    public function getKianEligibilityReason(): ?string
    {
        $reasons = [];
        foreach ($this->exposureByStage() as $stage => $result) {
            if ($result['needsKian'] === true) $reasons[] = "Stage {$stage} remaining company tax exposure: {$result['remainingExposure']}";
        }
        return $reasons ? implode(' | ', $reasons) : null;
    }

    /**
     * ✅ NEW METHOD 1: Check if KIAN is needed at a specific stage
     * Multiple KIAN per stage concept (v2)
     */
    public function needsKianAtStage(int $stageId): ?bool
    {
        return $this->exposureByStage()[$stageId]['needsKian'] ?? null;
    }

    /**
     * ✅ NEW METHOD 2: Calculate loss amount at a specific stage
     * Returns null if no loss at that stage
     */
    public function calculateLossAtStage(int $stageId): ?string
    {
        $result = $this->exposureByStage()[$stageId] ?? null;
        return ($result['needsKian'] ?? false) ? $result['remainingExposure'] : null;
    }

    public function exposureByStage(): array
    {
        return app(\App\Services\DecisionPointTaxExposure::class)->evaluate($this);
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
            ->where('kian_submissions.stage_id', $stageId)
            ->exists();
    }

    /**
     * ✅ NEW METHOD 4: Get eligibility reason for KIAN at specific stage
     * Returns null if no KIAN needed at stage, otherwise returns human-readable reason
     */
    public function getKianEligibilityReasonForStage(int $stageId): ?string
    {
        $amount = $this->calculateLossAtStage($stageId);
        return $amount === null ? null : "Stage {$stageId} remaining company tax exposure: {$amount}";
    }

    /**
     * ✅ NEW METHOD 5: Get comprehensive KIAN status for all 4 stages
     * Returns array with status for each stage (4, 7, 10, 12)
     * Used in API response for frontend
     */
    public function getKianStatusByStage(): array
    {
        $this->loadMissing('kianSubmissions');
        $status = $this->exposureByStage();
        foreach ($status as $stageId => &$result) {
            $kian = $this->kianSubmissions->firstWhere('stage_id', $stageId);
            $amount = $result['remainingExposure'];
            $result += [
                'lossAmount' => $result['needsKian'] === true ? $amount : null,
                'reason' => $result['needsKian'] === true ? "Stage {$stageId} remaining company tax exposure: {$amount}" : $result['evaluationReason'],
                'submitted' => $kian ? $kian->status !== 'draft' : false,
                'kianId' => $kian?->id,
                'kianStatus' => $kian?->status,
            ];
        }
        unset($result);
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

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
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
