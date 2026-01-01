<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $table = 'currencies';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'exchange_rate',
        'last_updated_at',
        'is_active',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'exchange_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'last_updated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function taxCases(): HasMany
    {
        return $this->hasMany(TaxCase::class);
    }
}
