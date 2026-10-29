<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Perusahaan (CV) di dalam Indoguna Group, masing-masing dengan instance SAP B1 sendiri. */
class BusinessUnit extends Model
{
    protected $table = 'business_units';

    protected $guarded = [];

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }
}
