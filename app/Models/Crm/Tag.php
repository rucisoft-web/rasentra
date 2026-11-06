<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $table = 'crm_tags';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
