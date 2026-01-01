<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankTransferRequest extends Model
{
    use SoftDeletes;

    protected $table = 'bank_transfer_requests';

    protected $fillable = [
        'refund_process_id',
        'transfer_number',
        'transfer_date',
        'transfer_amount',
        'bank_code',
        'bank_name',
        'account_number',
        'account_name',
        'transfer_status',
        'created_by',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'transfer_amount' => 'decimal:2',
        'transfer_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function refundProcess(): BelongsTo
    {
        return $this->belongsTo(RefundProcess::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
