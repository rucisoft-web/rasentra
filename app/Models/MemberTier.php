<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberTier extends Model
{
    protected $table = 'member_tiers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['min_spend' => 'decimal:2', 'point_multiplier' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
