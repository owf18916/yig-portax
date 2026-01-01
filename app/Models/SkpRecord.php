<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'skp_amount',
        'assessment_details',
        'next_stage',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'skp_amount' => 'decimal:2',
        'assessment_details' => 'json',
        'next_stage' => 'integer',
        'issue_date' => 'date',
        'receipt_date' => 'date',
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
