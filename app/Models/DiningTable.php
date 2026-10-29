<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiningTable extends Model
{
    protected $table = 'dining_tables';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
