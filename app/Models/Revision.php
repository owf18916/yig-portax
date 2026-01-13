<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Revision extends Model
{
    protected $table = 'revisions';

    protected $fillable = [
        'revisable_type',
        'revisable_id',
        'revision_status',
        'original_data',
        'revised_data',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'approval_reason',
        'rejection_reason',
        'submitted_by',
        'submitted_at',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    protected $casts = [
        'original_data' => 'json',
        'revised_data' => 'json',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Polymorphic relationships
    public function revisable(): MorphTo
    {
        return $this->morphTo();
    }

    // User relationships
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // Helper methods
    public function isPending(): bool
    {
        return $this->revision_status === 'PENDING_APPROVAL';
    }

    public function isApproved(): bool
    {
        return $this->revision_status === 'APPROVED';
    }

    public function isSubmitted(): bool
    {
        return $this->revision_status === 'SUBMITTED';
    }

    public function isGranted(): bool
    {
        return $this->revision_status === 'GRANTED';
    }

    public function isRejected(): bool
    {
        return $this->revision_status === 'REJECTED';
    }

    public function isNotGranted(): bool
    {
        return $this->revision_status === 'NOT_GRANTED';
    }

    public function isCompleted(): bool
    {
        return in_array($this->revision_status, ['GRANTED', 'REJECTED', 'NOT_GRANTED']);
    }
}
