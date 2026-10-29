<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CookingJob extends Model
{
    use HasUuids;

    protected $table = 'cooking_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw_weight' => 'decimal:6',
            'fee_amount' => 'decimal:2',
        ];
    }
}
