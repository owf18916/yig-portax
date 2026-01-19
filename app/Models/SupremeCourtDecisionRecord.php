<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupremeCourtDecisionRecord extends Model
{
    use SoftDeletes;

    protected $table = 'supreme_court_decision_records';

    protected $fillable = [
        'tax_case_id',
        'keputusan_pk_number',
        'keputusan_pk_date',
        'keputusan_pk',
        'keputusan_pk_amount',
        'keputusan_pk_notes',
        'next_action'
    ];

    protected $casts = [
        'keputusan_pk_date' => 'date',
        'keputusan_pk_amount' => 'decimal:2',
    ];

    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }
}
