<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnLine extends Model
{
    protected $table = 'sales_return_lines';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
