<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'tax_case_id',
        'document_type',
        'stage_code',
        'original_filename',
        'file_path',
        'file_mime_type',
        'file_size',
        'hash',
        'description',
        'uploaded_by',
        'uploaded_at',
        'version',
        'previous_version_id',
        'status',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'version' => 'integer',
        'uploaded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Include name accessor in JSON
    protected $appends = ['name'];

    // Relationships
    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'previous_version_id');
    }

    public function nextVersions()
    {
        return $this->hasMany(Document::class, 'previous_version_id', 'id');
    }

    public function documentable()
    {
        return $this->morphTo();
    }

    /**
     * Get the document name (alias for original_filename)
     */
    public function getNameAttribute()
    {
        return $this->original_filename ?? $this->file_path;
    }
}
