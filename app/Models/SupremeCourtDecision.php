<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * SupremeCourtDecision Model (Stage 12)
 * 
 * Represents final decision from Supreme Court (Peninjauan Kembali)
 * Can trigger:
 * - Refund process (if create_refund=true and status='approved')
 * - KIAN process (if loss exists) - REQUIRED for rejected/partial decisions
 * FINAL STAGE - no further progression
 */
class SupremeCourtDecision extends Model
{
    use SoftDeletes;

    protected $table = 'supreme_court_decisions';

    protected $fillable = [
        'tax_case_id',
        'decision_number',
        'decision_date',
        'decision_type',
        'decision_amount',
        'decision_notes',
        'next_action',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
        'create_refund',
        'refund_amount',
        'continue_to_next_stage',
    ];

    protected $casts = [
        'decision_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'create_refund' => 'boolean',
        'continue_to_next_stage' => 'boolean',
        'decision_date' => 'date',
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

    /**
     * ✅ REFACTORED: Create a refund process if permitted
     * 
     * Validation:
     * - create_refund flag must be true
     * - refund_amount must be > 0
     * - Decision status must be 'approved' (not draft/rejected)
     * - This is a MANUAL user action, not automatic
     * - This is FINAL STAGE - no further decision stages
     * 
     * Uses stage_id = RefundProcess::STAGE_ID_SUPREME_COURT (12) to identify origin
     * 
     * @throws \Exception If decision is not approved
     * @return RefundProcess|null
     */
    public function createRefundIfNeeded(): ?RefundProcess
    {
        // Validation 1: User must have explicitly chosen to create refund
        if (!$this->create_refund || !$this->refund_amount) {
            return null;
        }

        // ✅ NEW: Validation 2: Decision must be approved before refund can be created
        if ($this->status !== 'approved') {
            Log::warning('[SupremeCourtDecision] Attempted refund creation without approval', [
                'supreme_court_decision_id' => $this->id,
                'current_status' => $this->status,
                'required_status' => 'approved',
            ]);
            throw new \Exception('Cannot create refund. Supreme Court decision must be approved first.');
        }

        // ✅ NEW: Use stage_id instead of stage_source
        return RefundProcess::create([
            'tax_case_id' => $this->tax_case_id,
            'stage_id' => RefundProcess::STAGE_ID_SUPREME_COURT,  // ✅ NEW
            'refund_number' => 'SUPREME_COURT-' . now()->format('YmdHis') . '-' . $this->id,
            'refund_date' => now()->toDateString(),
            'refund_amount' => $this->refund_amount,
            // ⚠️ Keep for backward compatibility during transition period
            'stage_source' => RefundProcess::STAGE_SOURCE_SUPREME_COURT,
            'sequence_number' => RefundProcess::getNextSequenceNumber($this->tax_case_id),
            'triggered_by_decision_id' => $this->id,
            'triggered_by_decision_type' => self::class,
            'submitted_by' => $this->submitted_by,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
    }
}
