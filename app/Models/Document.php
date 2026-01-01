<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $table = 'documents';

    protected $fillable = [
        'tax_case_id',
        'documentable_type',
        'documentable_id',
        'stage_number',
        'document_type',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'uploaded_by',
        'uploaded_at',
        'is_verified',
        'verified_by',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'stage_number' => 'integer',
        'is_verified' => 'boolean',
        'uploaded_at' => 'datetime',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Relationships
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Get the documentable model
     */
    public function documentable()
    {
        return $this->morphTo();
    }
}
