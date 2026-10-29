<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CountLine extends Model
{
    use HasUuids;

    protected $table = 'count_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
        ];
    }

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
