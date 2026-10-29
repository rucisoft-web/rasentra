<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pack extends Model
{
    use HasUuids;

    protected $table = 'packs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'net_weight' => 'decimal:6',
            'label_price' => 'decimal:2',
            'packed_at' => 'datetime',
            'expires_at' => 'date',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PackEvent::class);
    }
}
