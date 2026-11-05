<?php

namespace App\Models\Crm;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOutlet extends Model
{
    protected $table = 'crm_customer_outlets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_on' => 'date'];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
