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
        'royalty_correction',
        'service_correction',
        'other_correction',
        'notes',
    ];

    protected $casts = [
        'skp_amount' => 'decimal:2',
        'royalty_correction' => 'decimal:2',
        'service_correction' => 'decimal:2',
        'other_correction' => 'decimal:2',
        'issue_date' => 'date',
        'receipt_date' => 'date',
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
