<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    protected $table = 'stock_balances';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
        ];
    }
}
