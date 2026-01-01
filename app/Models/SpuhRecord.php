<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpuhRecord extends Model
{
    use SoftDeletes;

    protected $table = 'spuh_records';

    protected $fillable = [
        'tax_case_id',
        'spuh_number',
        'issue_date',
        'receipt_date',
        'explanation_required',
        'explanation_provided',
        'explanation_date',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'explanation_required' => 'boolean',
        'explanation_provided' => 'boolean',
        'issue_date' => 'date',
        'receipt_date' => 'date',
        'explanation_date' => 'date',
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
