<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    protected $table = 'orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'promotion_snapshot' => 'array',
            'finalized_at' => 'datetime',
            'promised_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('line_no');
    }

    public function activeLines(): HasMany
    {
        return $this->lines()->where('status', 'active');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'held', 'confirmed'], true);
    }

    /** Struk final yang bisa dicetak ulang dan diretur. */
    public function isFinal(): bool
    {
        return $this->doc_no !== null && $this->finalized_at !== null;
    }
}
