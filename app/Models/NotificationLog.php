<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class NotificationLog extends Model {
    public const TYPE_KIAN_REMINDER='KIAN_REMINDER';
    public const NOT_ELIGIBLE='NOT_ELIGIBLE', QUEUED='QUEUED', SENT='SENT', SKIPPED_NO_RECIPIENT='SKIPPED_NO_RECIPIENT', FAILED='FAILED', PROCESSING='PROCESSING';
    protected $fillable=['tax_case_id','entity_id','stage_id','notification_type','idempotency_key','current_status','total_attempts','reason','first_queued_at','last_attempt_at','sent_at'];
    protected $casts=['first_queued_at'=>'datetime','last_attempt_at'=>'datetime','sent_at'=>'datetime'];
    public function taxCase(): BelongsTo { return $this->belongsTo(TaxCase::class); }
    public function entity(): BelongsTo { return $this->belongsTo(Entity::class); }
    public function attempts(): HasMany { return $this->hasMany(NotificationAttempt::class); }
}
