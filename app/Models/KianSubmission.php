<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KianSubmission extends Model
{
    use SoftDeletes;

    protected $table = 'kian_submissions';

    protected $fillable = [
        'tax_case_id',
        'stage_id',
        'kian_number',
        'submission_date',
        'kian_amount',
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
        'kian_amount' => 'decimal:2',
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
}
