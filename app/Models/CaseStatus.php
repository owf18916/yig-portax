<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseStatus extends Model
{
    protected $table = 'case_statuses';

    protected $fillable = [
        'code',
        'name',
        'description',
        'stage_number',
        'category',
        'color',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'stage_number' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function taxCases(): HasMany
    {
        return $this->hasMany(TaxCase::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class, 'new_status_id');
    }
}
