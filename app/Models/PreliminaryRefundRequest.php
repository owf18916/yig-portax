<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PreliminaryRefundRequest extends Model
{
    use SoftDeletes;

    protected $table = 'preliminary_refund_requests';

    // Status constants
    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'tax_case_id',
        'request_number',
        'submission_date',
        'requested_amount',
        'approval_status',
        'approved_amount',
        'approved_date',
        'notes',
        'next_action',
        'next_action_due_date',
        'status_comment',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'submission_date' => 'date',
        'approved_date' => 'date',
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

    // Status check methods
    public function isPending(): bool
    {
        return $this->approval_status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->approval_status === self::STATUS_REJECTED;
    }

    /**
     * Approve the preliminary refund request
     * 
     * @param float $amount
     * @return $this
     */
    public function approve(float $amount)
    {
        $this->update([
            'approval_status' => self::STATUS_APPROVED,
            'approved_amount' => $amount,
            'approved_date' => now()->toDateString(),
            'approved_by' => auth()?->id(),
            'approved_at' => now(),
        ]);

        // Create refund process with stage_source PRELIMINARY
        $this->createRefundIfApproved();

        return $this;
    }

    /**
     * Reject the preliminary refund request
     * 
     * @param string $reason
     * @return $this
     */
    public function reject(string $reason)
    {
        $this->update([
            'approval_status' => self::STATUS_REJECTED,
            'status_comment' => $reason,
            'approved_by' => auth()?->id(),
            'approved_at' => now(),
        ]);

        // Trigger KIAN email reminder if needed
        // This will be handled by controller after rejection

        return $this;
    }

    /**
     * Create refund process if this request is approved
     * 
     * @return RefundProcess|null
     */
    public function createRefundIfApproved(): ?RefundProcess
    {
        if (!$this->isApproved()) {
            return null;
        }

        // Check if refund already exists for this preliminary request
        $existingRefund = RefundProcess::where('tax_case_id', $this->tax_case_id)
            ->where('stage_source', RefundProcess::STAGE_SOURCE_PRELIMINARY)
            ->first();

        if ($existingRefund) {
            return $existingRefund;
        }

        // Generate refund number
        $refundNumber = 'PRF-' . now()->format('YmdHis') . '-' . $this->id;

        // Create refund process
        return RefundProcess::create([
            'tax_case_id' => $this->tax_case_id,
            'refund_number' => $refundNumber,
            'refund_date' => now()->toDateString(),
            'refund_method' => 'BANK_TRANSFER',
            'refund_amount' => $this->approved_amount,
            'refund_status' => 'PENDING',
            'stage_source' => RefundProcess::STAGE_SOURCE_PRELIMINARY,
            'sequence_number' => RefundProcess::getNextSequenceNumber($this->tax_case_id),
            'triggered_by_decision_id' => $this->id,
            'triggered_by_decision_type' => self::class,
            'submitted_by' => auth()?->id(),
            'submitted_at' => now(),
            'status' => 'submitted',
            'notes' => 'Automatically created from approved preliminary refund request',
        ]);
    }
}
