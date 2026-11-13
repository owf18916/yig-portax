<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KianSubmission extends Model
{
    use SoftDeletes;

    public const FINAL_FIELDS = ['response_status', 'response_reference', 'response_issued_date', 'response_received_date', 'approved_amount', 'response_notes', 'response_document_id', 'responded_by', 'responded_at', 'closed_by', 'closed_at'];

    protected $hidden = ['respondedBy', 'closedBy', 'responseDocument'];

    protected $table = 'kian_submissions';

    protected $fillable = [
        'tax_case_id',
        'stage_id',
        'kian_number',
        'submission_date',
        'kian_amount',
        'write_off_amount',
        'loss_amount',
        'loss_description',
        'supporting_documents',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'status',
        'notes',
        'next_action',
        'next_action_due_date',
        'status_comment',
    ];

    protected $casts = [
        'approved_amount' => 'decimal:2',
        'response_issued_date' => 'date',
        'response_received_date' => 'date',
        'responded_at' => 'datetime',
        'closed_at' => 'datetime',
        'kian_amount' => 'decimal:2',
        'write_off_amount' => 'decimal:2',
        'loss_amount' => 'decimal:2',
        'supporting_documents' => 'json',
        'submission_date' => 'date',
        'next_action_due_date' => 'date',
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

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function responseDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'response_document_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $kian) {
            if (in_array($kian->getRawOriginal('status'), ['responded', 'closed'], true)) {
                $allowed = $kian->getRawOriginal('status') === 'responded' ? ['status', 'closed_by', 'closed_at', 'updated_at'] : ['updated_at'];
                abort_if(array_diff(array_keys($kian->getDirty()), $allowed) !== [], 422, 'KIAN final evidence is immutable.');
                if ($kian->isDirty('status')) abort_unless($kian->status === 'closed', 422, 'KIAN cannot be reopened.');
            }
        });
        static::deleting(function (self $kian) {
            abort_if($kian->response_document_id !== null, 422, 'KIAN final evidence cannot be deleted.');
        });
    }
}
