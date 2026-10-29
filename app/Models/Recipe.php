<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $table = 'recipes';

    protected $guarded = [];

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class);
    }
}
