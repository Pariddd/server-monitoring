<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertRule extends Model
{
    protected $fillable = [
        'key',
        'name',
        'kind',
        'source_type',
        'metric',
        'operator',
        'threshold',
        'recover_operator',
        'recover_threshold',
        'for_seconds',
        'cooldown_seconds',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'recover_threshold' => 'float',
            'for_seconds' => 'integer',
            'cooldown_seconds' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    public function states(): HasMany
    {
        return $this->hasMany(AlertState::class);
    }
}
