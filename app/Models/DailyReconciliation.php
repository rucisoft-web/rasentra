<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReconciliation extends Model
{
    protected $table = 'daily_reconciliations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'closed_at' => 'datetime',
            'checked_at' => 'datetime',
            'local_net' => 'decimal:2',
            'central_net' => 'decimal:2',
            'cash_difference' => 'decimal:2',
            'local_summary' => 'array',
            'missing_doc_nos' => 'array',
            'extra_doc_nos' => 'array',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
