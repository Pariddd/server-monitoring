<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = ['source_id', 'metric', 'tag', 'value', 'recorded_at', 'received_at'];

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
