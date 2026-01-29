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

    // Stage source constants
    const STAGE_SOURCE_PRELIMINARY = 'PRELIMINARY';
    const STAGE_SOURCE_SKP = 'SKP';
    const STAGE_SOURCE_OBJECTION = 'OBJECTION';
    const STAGE_SOURCE_APPEAL = 'APPEAL';
    const STAGE_SOURCE_SUPREME_COURT = 'SUPREME_COURT';

    protected $fillable = [
        'tax_case_id',
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
        'stage_source',
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

    // Scopes for filtering by stage source
    public function scopeByStageSource(Builder $query, string $stageSource): Builder
    {
        return $query->where('stage_source', $stageSource);
    }

    public function scopeLatestForTaxCase(Builder $query): Builder
    {
        return $query->orderBy('sequence_number', 'desc');
    }

    // Helper method to get human-readable stage source label
    public function getStageLabelAttribute(): string
    {
        return match($this->stage_source) {
            self::STAGE_SOURCE_PRELIMINARY => 'Pengembalian Pendahuluan',
            self::STAGE_SOURCE_SKP => 'SKP',
            self::STAGE_SOURCE_OBJECTION => 'Objection Decision',
            self::STAGE_SOURCE_APPEAL => 'Appeal Decision',
            self::STAGE_SOURCE_SUPREME_COURT => 'Supreme Court Decision',
            default => 'Unknown',
        };
    }

    /**
     * Get the next sequence number for a tax case
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

