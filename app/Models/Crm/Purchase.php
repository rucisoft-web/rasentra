<?php

namespace App\Models\Crm;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    protected $table = 'crm_purchases';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
