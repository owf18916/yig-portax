<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class NotificationAttempt extends Model {
    protected $fillable=['notification_log_id','attempt_number','status','reason','to_recipients','cc_recipients','bcc_recipients','initiated_type','initiated_by','queued_at','started_at','finished_at','error_message'];
    protected $casts=['to_recipients'=>'array','cc_recipients'=>'array','bcc_recipients'=>'array','queued_at'=>'datetime','started_at'=>'datetime','finished_at'=>'datetime'];
    public function notificationLog(): BelongsTo { return $this->belongsTo(NotificationLog::class); }
    public function initiatedBy(): BelongsTo { return $this->belongsTo(User::class,'initiated_by'); }
}
