<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppealDecision extends Model
{
    use SoftDeletes;

    protected $table = 'appeal_decisions';

    protected $fillable = [
        'tax_case_id',
        'decision_number',
        'decision_date',
        'decision_type',
        'decision_amount',
        'decision_notes',
        'next_stage',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
        'next_action',
        'next_action_due_date',
        'status_comment',
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
        'next_action_due_date' => 'date',
        'next_stage' => 'integer',
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
     * Create a refund process if create_refund is true
     * 
     * @return RefundProcess|null
     */
    public function createRefundIfNeeded(): ?RefundProcess
    {
        if (!$this->create_refund || !$this->refund_amount) {
            return null;
        }

        return RefundProcess::create([
            'tax_case_id' => $this->tax_case_id,
            'refund_number' => 'APPEAL-' . now()->format('YmdHis') . '-' . $this->id,
            'refund_date' => now()->toDateString(),
            'refund_amount' => $this->refund_amount,
            'stage_source' => RefundProcess::STAGE_SOURCE_APPEAL,
            'sequence_number' => RefundProcess::getNextSequenceNumber($this->tax_case_id),
            'triggered_by_decision_id' => $this->id,
            'triggered_by_decision_type' => 'AppealDecision',
            'submitted_by' => $this->submitted_by,
            'submitted_at' => now(),
            'status' => 'submitted',
        ]);
    }
}
