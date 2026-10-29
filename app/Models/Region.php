<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Region extends Model
{
    protected $table = 'regions';

    protected $guarded = [];

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }
}
