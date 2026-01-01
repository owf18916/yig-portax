<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RefundProcess extends Model
{
    use SoftDeletes;

    protected $table = 'refund_processes';

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
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'bank_details' => 'json',
        'refund_date' => 'date',
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
}
