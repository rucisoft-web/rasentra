<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasUuids;

    protected $table = 'stock_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'occurred_at' => 'datetime',
            'business_date' => 'date',
        ];
    }

    public $timestamps = false;
}
