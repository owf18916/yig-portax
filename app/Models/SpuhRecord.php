<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpuhRecord extends Model
{
    use SoftDeletes;

    protected $table = 'spuh_records';

    protected $fillable = [
        'tax_case_id',
        'spuh_number',
        'issue_date',
        'receipt_date',
        'reply_number',
        'reply_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'receipt_date' => 'date',
        'reply_date' => 'date',
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
