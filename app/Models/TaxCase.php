<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxCase extends Model
{
    use SoftDeletes;

    protected $table = 'tax_cases';

    protected $fillable = [
        'user_id',
        'entity_id',
        'fiscal_year_id',
        'period_id',
        'currency_id',
        'case_status_id',
        'case_number',
        'case_type',
        'spt_number',
        'filing_date',
        'received_date',
        'reported_amount',
        'disputed_amount',
        'vat_in_amount',
        'vat_out_amount',
        'current_stage',
        'is_completed',
        'completed_date',
        'description',
        'refund_amount',
        'refund_date',
    ];

    protected $casts = [
        'reported_amount' => 'decimal:2',
        'disputed_amount' => 'decimal:2',
        'vat_in_amount' => 'decimal:2',
        'vat_out_amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'current_stage' => 'integer',
        'is_completed' => 'boolean',
        'filing_date' => 'date',
        'received_date' => 'date',
        'completed_date' => 'date',
        'refund_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $appends = ['entity_name'];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(CaseStatus::class, 'case_status_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Stage relationships
    public function sp2Record(): HasOne
    {
        return $this->hasOne(Sp2Record::class);
    }

    public function sphpRecord(): HasOne
    {
        return $this->hasOne(SphpRecord::class);
    }

    public function skpRecord(): HasOne
    {
        return $this->hasOne(SkpRecord::class);
    }

    public function objectionSubmission(): HasOne
    {
        return $this->hasOne(ObjectionSubmission::class);
    }

    public function spuhRecord(): HasOne
    {
        return $this->hasOne(SpuhRecord::class);
    }

    public function objectionDecision(): HasOne
    {
        return $this->hasOne(ObjectionDecision::class);
    }

    public function appealSubmission(): HasOne
    {
        return $this->hasOne(AppealSubmission::class);
    }

    public function appealExplanationRequest(): HasOne
    {
        return $this->hasOne(AppealExplanationRequest::class);
    }

    public function appealDecision(): HasOne
    {
        return $this->hasOne(AppealDecision::class);
    }

    // Revision relationship
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'revisable_id')->where('revisable_type', 'TaxCase');
    }

    public function lastRevision(): BelongsTo
    {
        return $this->belongsTo(Revision::class, 'last_revision_id');
    }

    public function supremeCourtSubmission(): HasOne
    {
        return $this->hasOne(SupremeCourtSubmission::class);
    }

    public function supremeCourtDecision(): HasOne
    {
        return $this->hasOne(SupremeCourtDecision::class);
    }

    public function supremeCourtDecisionRecord(): HasOne
    {
        return $this->hasOne(SupremeCourtDecisionRecord::class);
    }

    public function kianSubmission(): HasOne
    {
        return $this->hasOne(KianSubmission::class);
    }

    public function refundProcess(): HasOne
    {
        return $this->hasOne(RefundProcess::class);
    }

    // Audit relationships
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'auditable_id')
            ->where('auditable_type', self::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class);
    }

    public function workflowHistories(): HasMany
    {
        return $this->hasMany(WorkflowHistory::class);
    }

    // Accessors
    public function getEntityNameAttribute()
    {
        return $this->entity?->name ?? 'Unknown Entity';
    }
}
