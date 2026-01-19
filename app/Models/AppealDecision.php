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
        'keputusan_banding_number',
        'keputusan_banding_date',
        'keputusan_banding',
        'keputusan_banding_amount',
        'keputusan_banding_notes',
        'user_routing_choice',
        'decision_letter_number',
        'decision_date',
        'decision_type',
        'decision_amount',
        'reasoning',
        'next_stage',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'decision_amount' => 'decimal:2',
        'decision_date' => 'date',
        'keputusan_banding_date' => 'date',
        'keputusan_banding_amount' => 'decimal:2',
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
