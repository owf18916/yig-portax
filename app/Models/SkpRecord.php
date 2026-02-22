<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * SkpRecord Model (Stage 4)
 * 
 * Represents SKP (Tax Assessment) decision.
 * Can trigger:
 * - Refund process (if create_refund=true and status='approved')
 * - KIAN process (if loss exists)
 * - Progress to next stage (if continue_to_next_stage=true)
 */
class SkpRecord extends Model
{
    use SoftDeletes;

    protected $table = 'skp_records';

    protected $fillable = [
        'tax_case_id',
        'skp_number',
        'issue_date',
        'receipt_date',
        'skp_type',
        'user_routing_choice',
        'skp_amount',
        'royalty_correction',
        'service_correction',
        'other_correction',
        'correction_notes',
        'notes',
        'next_action',
        'next_action_due_date',
        'status_comment',
        'create_refund',
        'refund_amount',
        'continue_to_next_stage',
        'submitted_by',
        'status',
    ];

    protected $casts = [
        'skp_amount' => 'decimal:2',
        'royalty_correction' => 'decimal:2',
        'service_correction' => 'decimal:2',
        'other_correction' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'create_refund' => 'boolean',
        'continue_to_next_stage' => 'boolean',
        'issue_date' => 'date',
        'receipt_date' => 'date',
        'next_action_due_date' => 'date',
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
     * 
     * Uses stage_id = RefundProcess::STAGE_ID_SKP (4) to identify origin
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
            Log::warning('[SkpRecord] Attempted refund creation without approval', [
                'skp_record_id' => $this->id,
                'current_status' => $this->status,
                'required_status' => 'approved',
            ]);
            throw new \Exception('Cannot create refund. SKP decision must be approved first.');
        }

        // ✅ NEW: Use stage_id instead of stage_source
        return RefundProcess::create([
            'tax_case_id' => $this->tax_case_id,
            'stage_id' => RefundProcess::STAGE_ID_SKP,  // ✅ NEW
            'refund_number' => 'SKP-' . now()->format('YmdHis') . '-' . $this->id,
            'refund_date' => now()->toDateString(),
            'refund_amount' => $this->refund_amount,
            // ⚠️ Keep for backward compatibility during transition period
            'stage_source' => RefundProcess::STAGE_SOURCE_SKP,
            'sequence_number' => RefundProcess::getNextSequenceNumber($this->tax_case_id),
            'triggered_by_decision_id' => $this->id,
            'triggered_by_decision_type' => self::class,
            'submitted_by' => $this->submitted_by,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
    }
}
