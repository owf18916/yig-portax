<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SphpRecord extends Model
{
    use SoftDeletes;

    protected $table = 'sphp_records';

    protected $fillable = [
        'tax_case_id',
        'sphp_number',
        'sphp_issue_date',
        'sphp_receipt_date',
        'royalty_finding',
        'service_finding',
        'other_finding',
        'other_finding_notes',
    ];

    protected $casts = [
        'royalty_finding' => 'decimal:2',
        'service_finding' => 'decimal:2',
        'other_finding' => 'decimal:2',
        'sphp_issue_date' => 'date',
        'sphp_receipt_date' => 'date',
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
