<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CountSession extends Model
{
    use HasUuids;

    protected $table = 'count_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'blind' => 'boolean',
            'cutoff_at' => 'datetime',
            'recount_threshold' => 'decimal:6',
            'recount_product_ids' => 'array',
            'summary' => 'array',
            'approved_at' => 'datetime',
        ];
    }
}
