<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    public const SPHP_OTHER_FINDINGS = 'sphp_other_findings_attachment';

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

    public function isPreliminaryEvidence(): bool
    {
        return $this->documentable_type === PreliminaryRefundRequest::class
            && in_array($this->document_type, ['preliminary_submission', 'preliminary_decision'], true);
    }

    public function isKianResponseEvidence(): bool
    {
        return $this->documentable_type === KianSubmission::class && $this->document_type === 'kian_final_response';
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->isPreliminaryEvidence() || $this->isKianResponseEvidence()) {
            unset($data['file_path']);
        }
        if ($this->isKianResponseEvidence() && $this->relationLoaded('uploadedBy')) {
            $data['uploaded_by'] = $this->uploadedBy?->only(['id', 'name']);
        }

        return $data;
    }

    public function scopeAccessibleKianEvidence($query)
    {
        $user = auth()->user();
        if (! $user || ! $user->is_active || ! $user->hasPermission('submit_cases')) {
            $query->where(fn ($q) => $q->where('documentable_type', '<>', KianSubmission::class)->orWhere('document_type', '<>', 'kian_final_response'));
        }
        return $query;
    }

    protected static function booted(): void
    {
        static::updating(function (Document $document) {
            abort_if($document->getRawOriginal('documentable_type') === KianSubmission::class && $document->getRawOriginal('document_type') === 'kian_final_response', 422, 'KIAN final response evidence is immutable.');
            abort_if($document->getRawOriginal('documentable_type') === PreliminaryRefundRequest::class, 422, 'Preliminary evidence is immutable.');
        });
        static::deleting(function (Document $document) {
            abort_if($document->isKianResponseEvidence(), 422, 'KIAN final response evidence cannot be deleted.');
            abort_if($document->isPreliminaryEvidence(), 422, 'Preliminary evidence cannot be deleted.');
        });
    }

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
