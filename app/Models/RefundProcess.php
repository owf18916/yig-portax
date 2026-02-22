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
    const STAGE_ID_PRELIMINARY = 0;      // Special: Preliminary Refund
    const STAGE_ID_SKP = 4;               // SKP Decision
    const STAGE_ID_OBJECTION = 7;         // Objection Decision
    const STAGE_ID_APPEAL = 10;           // Appeal Decision
    const STAGE_ID_SUPREME_COURT = 12;    // Supreme Court Decision

    // ⚠️ DEPRECATED: stage_source will be removed in future migration
    const STAGE_SOURCE_PRELIMINARY = 'PRELIMINARY';
    const STAGE_SOURCE_SKP = 'SKP';
    const STAGE_SOURCE_OBJECTION = 'OBJECTION';
    const STAGE_SOURCE_APPEAL = 'APPEAL';
    const STAGE_SOURCE_SUPREME_COURT = 'SUPREME_COURT';

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
}

