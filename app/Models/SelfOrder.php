<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SelfOrder extends Model
{
    use HasUuids;

    protected $table = 'self_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'estimated_total' => 'decimal:2', 'handled_at' => 'datetime'];
    }
}
