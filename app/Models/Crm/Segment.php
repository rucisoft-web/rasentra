<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;

class Segment extends Model
{
    protected $table = 'crm_segments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }
}
