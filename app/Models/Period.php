<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    protected $table = 'periods';

    protected $fillable = [
        'fiscal_year_id',
        'period_code',
        'year',
        'month',
        'start_date',
        'end_date',
        'is_closed',
    ];

    protected $casts = [
        'fiscal_year_id' => 'integer',
        'year' => 'integer',
        'month' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_closed' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function taxCases(): HasMany
    {
        return $this->hasMany(TaxCase::class);
    }
}
