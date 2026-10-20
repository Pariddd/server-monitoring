<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertEvent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['alert_state_id', 'from_state', 'to_state', 'value', 't_breach', 't_detected'];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            't_breach' => 'datetime',
            't_detected' => 'datetime',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(AlertState::class, 'alert_state_id');
    }
}
