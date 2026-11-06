<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use HasUuids;

    protected $table = 'deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_amount' => 'decimal:2', 'fee' => 'decimal:2', 'business_date' => 'date', 'promised_at' => 'datetime', 'assigned_at' => 'datetime', 'departed_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
