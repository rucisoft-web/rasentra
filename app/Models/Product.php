<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_weighed' => 'boolean',
            'is_stocked' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
