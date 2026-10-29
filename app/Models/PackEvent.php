<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackEvent extends Model
{
    protected $table = 'pack_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public $timestamps = false;
}
