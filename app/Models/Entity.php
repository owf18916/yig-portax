<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Entity extends Model
{
    use SoftDeletes;

    protected $table = 'entities';

    protected $fillable = [
        'code',
        'name',
        'entity_type',
        'parent_entity_id',
        'tax_id',
        'registration_number',
        'business_address',
        'city',
        'province',
        'postal_code',
        'country',
        'phone',
        'fax',
        'email',
        'industry_code',
        'industry_name',
        'annual_revenue',
        'employee_count',
        'business_status',
        'established_date',
        'is_active',
    ];

    protected $casts = [
        'annual_revenue' => 'decimal:2',
        'employee_count' => 'integer',
        'is_active' => 'boolean',
        'established_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function parentEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'parent_entity_id');
    }

    public function childEntities(): HasMany
    {
        return $this->hasMany(Entity::class, 'parent_entity_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function taxCases(): HasMany
    {
        return $this->hasMany(TaxCase::class);
    }
}
