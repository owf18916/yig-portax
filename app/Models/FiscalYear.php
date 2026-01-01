<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $table = 'fiscal_years';

    protected $fillable = [
        'year',
        'start_date',
        'end_date',
        'is_active',
        'is_closed',
    ];

    protected $casts = [
        'year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'is_closed' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function periods(): HasMany
    {
        return $this->hasMany(Period::class);
    }

    public function taxCases(): HasMany
    {
        return $this->hasMany(TaxCase::class);
    }
}
