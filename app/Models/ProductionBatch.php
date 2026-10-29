<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductionBatch extends Model
{
    use HasUuids;

    protected $table = 'production_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'outputs' => 'array',
            'loss_quantity' => 'decimal:6',
            'produced_at' => 'datetime',
        ];
    }
}
