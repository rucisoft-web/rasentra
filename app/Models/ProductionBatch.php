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
            'expected_quantity' => 'decimal:6',
            'loss_quantity' => 'decimal:6',
            'produced_at' => 'datetime',
        ];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipeVersion(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }
}
