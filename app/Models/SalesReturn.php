<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturn extends Model
{
    use HasUuids;

    protected $table = 'sales_returns';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class)->orderBy('line_no');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
