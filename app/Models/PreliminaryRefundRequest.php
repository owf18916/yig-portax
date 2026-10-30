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
    const STATUS_SUBMITTED = 'SUBMITTED';

    const STATUS_PENDING = 'PENDING';
    const STATUS_APPROVED = 'APPROVED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_POSTPONED = 'POSTPONED';

    protected $fillable = [
        'submission_document_id',
        'decision_document_id',
        'decision_date',
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
        'decision_date' => 'date',
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

    public function submissionDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'submission_document_id');
    }

    public function decisionDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'decision_document_id');
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
     * @param string|int $amount
     * @return $this
     */
    public function approve(mixed $amount)
    {
        return app(\App\Services\PreliminaryRefundLifecycle::class)->decide($this->tax_case_id, $this->id, 'approve', $amount);
    }

    /**
     * Reject the preliminary refund request
     * 
     * @param string $reason
     * @return $this
     */
    public function reject(string $reason)
    {
        return app(\App\Services\PreliminaryRefundLifecycle::class)->decide($this->tax_case_id, $this->id, 'reject', null, $reason);
    }

    public function postpone(string $reason)
    {
        return app(\App\Services\PreliminaryRefundLifecycle::class)->decide($this->tax_case_id, $this->id, 'postpone', null, $reason);
    }

    /**
     * Create refund process if this request is approved
     * 
     * @return RefundProcess|null
     */
    public function createRefundIfApproved(): ?RefundProcess
    {
        if (! $this->isApproved()) {
            return null;
        }

        $this->approve($this->approved_amount);
        return RefundProcess::where('tax_case_id', $this->tax_case_id)
            ->where('triggered_by_decision_type', self::class)
            ->where('triggered_by_decision_id', $this->id)->sole();
    }
}
