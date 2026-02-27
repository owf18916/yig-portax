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
        'request_number',
        'request_date',
        'transfer_number',
        'instruction_number',
        'instruction_issue_date',
        'instruction_received_date',
        'transfer_date',
        'processed_date',
        'received_date',
        'transfer_amount',
        'received_amount',
        'bank_code',
        'bank_name',
        'account_number',
        'account_holder',
        'account_name',
        'receipt_number',
        'transfer_status',
        'created_by',
        'rejection_reason',
        'notes',
    ];

    protected $casts = [
        'transfer_amount' => 'decimal:2',
        'received_amount' => 'decimal:2',
        'request_date' => 'date',
        'instruction_issue_date' => 'date',
        'instruction_received_date' => 'date',
        'transfer_date' => 'date',
        'processed_date' => 'date',
        'received_date' => 'date',
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
