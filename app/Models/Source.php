<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['name', 'type', 'token_hash', 'ip_address', 'ip_updated_at', 'last_seen_at', 'is_active'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'ip_updated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    public function alertStates(): HasMany
    {
        return $this->hasMany(AlertState::class);
    }
}
