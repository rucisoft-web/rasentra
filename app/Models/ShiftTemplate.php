<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftTemplate extends Model
{
    protected $table = 'shift_templates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['outlet_ids' => 'array', 'is_active' => 'boolean'];
    }
}
