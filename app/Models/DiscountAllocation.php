<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountAllocation extends Model
{
    protected $table = 'discount_allocations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
