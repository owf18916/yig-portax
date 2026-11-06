<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationAttempt extends Model
{
    protected $fillable = ['notification_log_id', 'attempt_number', 'status', 'reason', 'to_recipients', 'cc_recipients', 'bcc_recipients', 'initiated_type', 'initiated_by', 'queued_at', 'started_at', 'finished_at', 'error_message', 'message_snapshot', 'recipient_snapshot', 'claim_token', 'send_boundary_at'];

    protected $hidden = ['error_message', 'message_snapshot', 'recipient_snapshot', 'claim_token'];

    protected static function booted(): void
    {
        static::updating(function ($attempt) {
            foreach (['message_snapshot', 'recipient_snapshot', 'to_recipients', 'cc_recipients', 'bcc_recipients'] as $field) {
                $frozen = in_array($field, ['message_snapshot', 'recipient_snapshot'], true)
                    ? $attempt->getRawOriginal($field) !== null : $attempt->getRawOriginal('recipient_snapshot') !== null;
                if ($frozen && $attempt->isDirty($field)) {
                    throw new \LogicException('Notification attempt evidence is immutable.');
                }
            }
        });
    }

    protected $casts = ['message_snapshot' => 'array', 'recipient_snapshot' => 'array', 'send_boundary_at' => 'datetime', 'to_recipients' => 'array', 'cc_recipients' => 'array', 'bcc_recipients' => 'array', 'queued_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function notificationLog(): BelongsTo
    {
        return $this->belongsTo(NotificationLog::class);
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
