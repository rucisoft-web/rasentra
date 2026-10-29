<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromotionTarget extends Model
{
    protected $table = 'promotion_targets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'acked_at' => 'datetime',
        ];
    }

    public $timestamps = false;
}
