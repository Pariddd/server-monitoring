<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertNotification extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'alert_state_id',
        'alert_event_id',
        'kind',
        'status',
        'attempts',
        'message',
        't_breach',
        't_detected',
        't_queued',
        't_sent',
        'telegram_message_id',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            't_breach' => 'datetime',
            't_detected' => 'datetime',
            't_queued' => 'datetime',
            't_sent' => 'datetime',
            'telegram_message_id' => 'integer',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(AlertState::class, 'alert_state_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AlertEvent::class, 'alert_event_id');
    }
}
