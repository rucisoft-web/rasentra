<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ServiceEntitlement extends Model
{
    use HasUuids;

    protected $table = 'service_entitlements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'remaining_weight' => 'decimal:6',
        ];
    }
}
