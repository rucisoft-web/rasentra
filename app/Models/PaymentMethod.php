<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $table = 'payment_methods';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['outlet_ids' => 'array', 'requires_reference' => 'boolean', 'is_active' => 'boolean'];
    }
}
