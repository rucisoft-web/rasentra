<?php

namespace App\Models\Crm;

use App\Models\BusinessUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccurateConnection extends Model
{
    protected $table = 'crm_accurate_connections';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['api_token' => 'encrypted', 'signature_secret' => 'encrypted', 'invoices_synced_until' => 'date', 'last_synced_at' => 'datetime', 'sync_customers' => 'boolean', 'sync_invoices' => 'boolean'];
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }
}
