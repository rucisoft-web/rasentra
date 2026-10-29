<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeVersion extends Model
{
    protected $table = 'recipe_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'yield_quantity' => 'decimal:6',
            'effective_from' => 'datetime',
            'outlet_ids' => 'array',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }
}
