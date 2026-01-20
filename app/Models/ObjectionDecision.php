<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ObjectionDecision extends Model
{
    use SoftDeletes;

    protected $table = 'objection_decisions';

    protected $fillable = [
        'tax_case_id',
        'decision_number',
        'decision_date',
        'decision_type',
        'decision_amount',
        'next_stage',
        'status',
        'next_action',
        'next_action_due_date',
        'status_comment',
    ];

    protected $casts = [
        'decision_amount' => 'decimal:2',
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
}
