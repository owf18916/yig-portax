<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupremeCourtSubmission extends Model
{
    use SoftDeletes;

    protected $table = 'supreme_court_submissions';

    protected $fillable = [
        'tax_case_id',
        'submission_number',
        'submission_date',
        'submission_amount',
        'supreme_court_letter_number',
        'review_amount',
        'status',
        'notes',
        'next_action',
        'next_action_due_date',
        'status_comment',
    ];

    protected $casts = [
        'submission_date' => 'date',
        'next_action_due_date' => 'date',
        'review_amount' => 'integer',
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
