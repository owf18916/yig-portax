<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sp2Record extends Model
{
    use SoftDeletes;

    protected $table = 'sp2_records';

    protected $fillable = [
        'tax_case_id',
        'sp2_number',
        'issue_date',
        'receipt_date',
        'auditor_name',
        'auditor_phone',
        'auditor_email',
        'notes',
        'next_action',
        'next_action_due_date',
        'status_comment',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'receipt_date' => 'date',
        'next_action_due_date' => 'date',
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
