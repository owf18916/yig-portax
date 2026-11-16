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
        'review_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // decimal(17,2) retains the original fifteen whole digits.
    public const REVIEW_AMOUNT_RULE = 'nullable|numeric|min:0|regex:/^\d{1,15}(?:\.\d{1,2})?$/D';

    public static function normalizeReviewAmount(mixed $value): ?string
    {
        \Illuminate\Support\Facades\Validator::make(['review_amount' => $value],
            ['review_amount' => self::REVIEW_AMOUNT_RULE])->validate();
        return $value === null ? null : (string) \Brick\Math\BigDecimal::of((string) $value)->toScale(2);
    }

    // Relationships
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }
}
