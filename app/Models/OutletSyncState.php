<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kondisi terakhir satu Rasentra Lokal menurut heartbeat-nya ke Rasentra Central. */
class OutletSyncState extends Model
{
    protected $table = 'outlet_sync_states';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'last_pull_at' => 'datetime',
            'outbox_oldest_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
