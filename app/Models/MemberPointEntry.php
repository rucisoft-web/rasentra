<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MemberPointEntry extends Model
{
    use HasUuids;

    protected $table = 'member_point_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['points' => 'decimal:2', 'order_amount' => 'decimal:2', 'business_date' => 'date', 'applied_at_central' => 'boolean'];
    }
}
