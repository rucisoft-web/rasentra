<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $table = 'crm_customers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['points_balance' => 'decimal:2', 'member_spend' => 'decimal:2', 'total_spent' => 'decimal:2', 'member_since' => 'date', 'birth_date' => 'date', 'is_member' => 'boolean'];
    }
}
