<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationLog extends Model
{
    public const TYPE_KIAN_REMINDER = 'KIAN_REMINDER';

    public const PRE_SEND_FAILED = 'PRE_SEND_FAILED';

    public const NO_VALID_TO = 'NO_VALID_TO';

    public const UNCERTAIN = 'DELIVERY_UNCERTAIN';

    public const SUPPRESSED = 'SUPPRESSED';

    public const NOT_ELIGIBLE = 'NOT_ELIGIBLE';

    public const QUEUED = 'QUEUED';

    public const SENT = 'SENT';

    public const SKIPPED_NO_RECIPIENT = 'SKIPPED_NO_RECIPIENT';

    public const FAILED = 'FAILED';

    public const PROCESSING = 'PROCESSING';

    protected $fillable = ['tax_case_id', 'entity_id', 'stage_id', 'notification_type', 'idempotency_key', 'current_status', 'total_attempts', 'reason', 'first_queued_at', 'last_attempt_at', 'sent_at'];

    protected $casts = ['event_snapshot' => 'array', 'first_queued_at' => 'datetime', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime'];

    protected $hidden = ['event_snapshot'];

    protected static function booted(): void
    {
        static::updating(function ($log) {
            if ($log->getRawOriginal('event_snapshot') !== null && $log->isDirty('event_snapshot')) {
                throw new \LogicException('Notification event evidence is immutable.');
            }
        });
    }

    public function setEventSnapshotAttribute($value): void
    {
        $this->attributes['event_snapshot'] = json_encode($value);
    }

    public function latestAttempt(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(NotificationAttempt::class)->ofMany('attempt_number', 'max');
    }

    public function taxCase(): BelongsTo
    {
        return $this->belongsTo(TaxCase::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class);
    }
}
