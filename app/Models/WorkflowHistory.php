<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowHistory extends Model
{
    protected $table = 'workflow_histories';

    public $timestamps = false;

    protected $fillable = [
        'tax_case_id',
        'stage_from',
        'stage_to',
        'action',
        'decision_point',
        'decision_value',
        'user_id',
        'notes',
        'created_at',
    ];

    protected $casts = [
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
