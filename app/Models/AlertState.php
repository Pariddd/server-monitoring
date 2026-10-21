<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertState extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'alert_rule_id',
        'source_id',
        'tag',
        'state',
        'state_since',
        'breach_at',
        'last_value',
        'last_notified_at',
        'last_evaluated_at',
        'last_sample_at',
    ];

    protected function casts(): array
    {
        return [
            'state_since' => 'datetime',
            'breach_at' => 'datetime',
            'last_value' => 'float',
            'last_sample_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'last_evaluated_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class, 'alert_rule_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }
}
