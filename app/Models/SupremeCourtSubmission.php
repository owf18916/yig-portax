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
        'supreme_court_letter_number',
        'submission_date',
        'review_amount',
        'status',
        'notes',
    ];

    protected $casts = [
        'submission_date' => 'date',
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
