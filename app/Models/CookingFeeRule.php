<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CookingFeeRule extends Model
{
    protected $table = 'cooking_fee_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'minimum_fee' => 'decimal:2',
            'tiers' => 'array',
            'included_items' => 'array',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }
}
