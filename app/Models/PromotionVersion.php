<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromotionVersion extends Model
{
    protected $table = 'promotion_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'channels' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PromotionTarget::class);
    }
}
