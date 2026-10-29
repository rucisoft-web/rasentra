<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeLine extends Model
{
    protected $table = 'recipe_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
        ];
    }
}
