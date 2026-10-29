<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConsumptionEvent extends Model
{
    use HasUuids;

    protected $table = 'consumption_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'occurred_at' => 'datetime',
        ];
    }

    public $timestamps = false;
}
