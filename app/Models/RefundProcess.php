<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

class RefundProcess extends Model
{
    use SoftDeletes;

    protected $table = 'refund_processes';

    // ✅ Stage ID constants (align with TaxCase stages)
    const STAGE_ID_PRELIMINARY = 0;      // Special: Preliminary Refund (allows multiple)
    const STAGE_ID_SKP = 4;               // SKP Decision (single refund)
    const STAGE_ID_OBJECTION = 7;         // Objection Decision (single refund)
    const STAGE_ID_APPEAL = 10;           // Appeal Decision (single refund)
    const STAGE_ID_SUPREME_COURT = 12;    // Supreme Court Decision (single refund)

    // ✅ Refund Stage constants for internal flow tracking (Refund Stage 1-4)
    const REFUND_STAGE_INITIATED = 'initiated';        // Refund Stage 1: Process initiated
    const REFUND_STAGE_TRANSFER_REQUEST = 'transfer_request';  // Refund Stage 2: Transfer request sent
    const REFUND_STAGE_INSTRUCTION = 'instruction';    // Refund Stage 3: Instruction received
    const REFUND_STAGE_COMPLETED = 'completed';        // Refund Stage 4: Refund completed

    // ⚠️ DEPRECATED: stage_source will be removed in future migration
    const STAGE_SOURCE_PRELIMINARY = 'PRELIMINARY';
    const STAGE_SOURCE_SKP = 'SKP';
    const STAGE_SOURCE_OBJECTION = 'OBJECTION';
    const STAGE_SOURCE_APPEAL = 'APPEAL';
    const STAGE_SOURCE_SUPREME_COURT = 'SUPREME_COURT';

    /**
     * Valid stage IDs for refund processes
     */
    const VALID_STAGE_IDS = [0, 4, 7, 10, 12];

    /**
     * Decision stages (NOT preliminary) where only 1 refund is allowed per tax_case
     */
    const DECISION_STAGE_IDS = [4, 7, 10, 12];

    protected $fillable = [
        'tax_case_id',
        'stage_id',  // ✅ NEW: Primary identifier for refund origin
        'refund_number',
        'refund_date',
        'refund_method',
        'refund_amount',
        'refund_status',
        'bank_details',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
        'next_action',
        'next_action_due_date',
        'status_comment',
        'stage_source',  // ⚠️ DEPRECATED: kept for backward compatibility
        'sequence_number',
        'triggered_by_decision_id',
        'triggered_by_decision_type',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'bank_details' => 'json',
        'refund_date' => 'date',
        'next_action_due_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * ✅ BOOT: Hook into model lifecycle to validate uniqueness constraints
     * Enforces: Only ONE refund per tax_case for decision stages (4,7,10,12)
     *           MULTIPLE refunds allowed for PRELIMINARY stage (0)
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Validate that decision stages can only have 1 refund per tax_case
            if (in_array($model->stage_id, self::DECISION_STAGE_IDS)) {
                $existingRefund = self::where('tax_case_id', $model->tax_case_id)
                    ->where('stage_id', $model->stage_id)
                    ->whereNull('deleted_at')  // Exclude soft-deleted records
                    ->exists();

                if ($existingRefund) {
                    throw new \Illuminate\Database\Eloquent\ModelNotFoundException(
                        "A refund already exists for this stage. Only one refund per decision stage is allowed. "
                        . "Stage ID: {$model->stage_id}, Tax Case ID: {$model->tax_case_id}"
                    );
                }
            }
            // Note: Preliminary (stage_id=0) allows multiple refunds - no validation needed
        });
    }

    // Relationships
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function bankTransferRequests(): HasMany
    {
        return $this->hasMany(BankTransferRequest::class);
    }

    /**
     * ✅ NEW: Filter refunds by stage ID
     */
    public function scopeByStageId(Builder $query, int $stageId): Builder
    {
        return $query->where('stage_id', $stageId);
    }

    // ⚠️ DEPRECATED: Use scopeByStageId instead
    public function scopeByStageSource(Builder $query, string $stageSource): Builder
    {
        return $query->where('stage_source', $stageSource);
    }

    public function scopeLatestForTaxCase(Builder $query): Builder
    {
        return $query->orderBy('sequence_number', 'desc');
    }

    /**
     * ✅ NEW: Get human-readable stage label based on stage_id
     */
    public function getStageLabelAttribute(): string
    {
        return match($this->stage_id) {
            self::STAGE_ID_PRELIMINARY => 'Pengembalian Pendahuluan',
            self::STAGE_ID_SKP => 'SKP Decision',
            self::STAGE_ID_OBJECTION => 'Objection Decision',
            self::STAGE_ID_APPEAL => 'Appeal Decision',
            self::STAGE_ID_SUPREME_COURT => 'Supreme Court Decision',
            default => 'Unknown Stage',
        };
    }

    /**
     * ✅ NEW: Check if refund is from preliminary (stage_id = 0)
     */
    public function isPreliminary(): bool
    {
        return $this->stage_id === self::STAGE_ID_PRELIMINARY;
    }

    /**
     * ✅ NEW: Check if refund is from decision stage (4, 7, 10, or 12)
     */
    public function isFromDecisionStage(): bool
    {
        return in_array($this->stage_id, [
            self::STAGE_ID_SKP,
            self::STAGE_ID_OBJECTION,
            self::STAGE_ID_APPEAL,
            self::STAGE_ID_SUPREME_COURT,
        ]);
    }

    /**
     * ✅ NEW: Map stage_id to decision model class
     */
    public static function getDecisionModelClassForStageId(int $stageId): ?string
    {
        return match($stageId) {
            self::STAGE_ID_SKP => 'App\\Models\\SkpRecord',
            self::STAGE_ID_OBJECTION => 'App\\Models\\ObjectionDecision',
            self::STAGE_ID_APPEAL => 'App\\Models\\AppealDecision',
            self::STAGE_ID_SUPREME_COURT => 'App\\Models\\SupremeCourtDecision',
            default => null,
        };
    }

    /**
     * ✅ NEW: Get decision record that triggered this refund
     * Returns the actual model instance (SkpRecord, ObjectionDecision, etc)
     */
    public function getTriggeredDecision()
    {
        if (!$this->triggered_by_decision_id || !$this->triggered_by_decision_type) {
            return null;
        }

        return $this->triggered_by_decision_type::find($this->triggered_by_decision_id);
    }

    /**
     * Get the next sequence number for a tax case
     * Sequence tracks the order of refunds within a single tax case
     * 
     * @param int $taxCaseId
     * @return int
     */
    public static function getNextSequenceNumber(int $taxCaseId): int
    {
        $lastRefund = self::where('tax_case_id', $taxCaseId)
            ->orderBy('sequence_number', 'desc')
            ->first();
        
        return ($lastRefund?->sequence_number ?? 0) + 1;
    }

    /**
     * ✅ NEW: Determine current Refund Stage (1-4) based on bank_transfer_requests status
     * 
     * Refund Stage 1 (INITIATED): refund_processes created, no bank transfer request yet
     * Refund Stage 2: bank_transfer_requests created with transfer_status='pending'
     * Refund Stage 3: transfer_status='processing' or instruction received
     * Refund Stage 4: transfer_status='completed' and received_date is set
     * 
     * @return int Stage number (1-4)
     */
    public function getCurrentRefundStage(): int
    {
        $latestTransfer = $this->bankTransferRequests()
            ->latest()
            ->first();

        // Stage 1: No bank transfer request yet
        if (!$latestTransfer) {
            return 1;
        }

        // Stage 4: Transfer completed
        if ($latestTransfer->transfer_status === 'completed' && $latestTransfer->received_date) {
            return 4;
        }

        // Stage 3: Instruction received (processing status + instruction_received_date)
        if ($latestTransfer->transfer_status === 'processing' && $latestTransfer->instruction_received_date) {
            return 3;
        }

        // Stage 2: Transfer request created (pending or processing without instruction yet)
        if ($latestTransfer->transfer_status === 'pending' || $latestTransfer->transfer_status === 'processing') {
            return 2;
        }

        // Default to stage 1 if status is unclear
        return 1;
    }

    /**
     * ✅ NEW: Check if refund is currently in Refund Stage 1
     */
    public function isRefundStage1(): bool
    {
        return $this->getCurrentRefundStage() === 1;
    }

    /**
     * ✅ NEW: Check if refund is currently in Refund Stage 2
     */
    public function isRefundStage2(): bool
    {
        return $this->getCurrentRefundStage() === 2;
    }

    /**
     * ✅ NEW: Check if refund is currently in Refund Stage 3
     */
    public function isRefundStage3(): bool
    {
        return $this->getCurrentRefundStage() === 3;
    }

    /**
     * ✅ NEW: Check if refund is currently in Refund Stage 4 (Completed)
     */
    public function isRefundStage4(): bool
    {
        return $this->getCurrentRefundStage() === 4;
    }
}

