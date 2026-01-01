<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SphpRecord extends Model
{
    use SoftDeletes;

    protected $table = 'sphp_records';

    protected $fillable = [
        'tax_case_id',
        'sphp_number',
        'issue_date',
        'receipt_date',
        'corrections',
        'additional_tax',
        'royalty_corrections',
        'service_corrections',
        'other_corrections',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'additional_tax' => 'decimal:2',
        'royalty_corrections' => 'json',
        'service_corrections' => 'json',
        'other_corrections' => 'json',
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
