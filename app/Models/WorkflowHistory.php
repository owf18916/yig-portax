<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowHistory extends Model
{
    protected $table = 'workflow_histories';

    public $timestamps = true;  // Changed from false - auto set created_at/updated_at

    protected $fillable = [
        'tax_case_id',
        'stage_id',
        'stage_from',
        'stage_to',
        'action',
        'status',
        'decision_point',
        'decision_value',
        'user_id',
        'notes',
    ];

    protected $casts = [
        'stage_id' => 'integer',
        'stage_from' => 'integer',
        'stage_to' => 'integer',
        'created_at' => 'datetime',
    ];

    // Relationships
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
