<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppealExplanationRequest extends Model
{
    use SoftDeletes;

    protected $table = 'appeal_explanation_requests';

    protected $fillable = [
        'tax_case_id',
        // Phase 1: Explanation Request Receipt
        'request_number',
        'request_issue_date',
        'request_receipt_date',
        // Phase 2: Explanation Submission (filled later by user)
        'explanation_letter_number',
        'explanation_submission_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'request_issue_date' => 'date',
        'request_receipt_date' => 'date',
        'explanation_submission_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }
}
