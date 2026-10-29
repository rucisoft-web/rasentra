<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    protected $table = 'batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'expiry_date' => 'date',
        ];
    }
}
