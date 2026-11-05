<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaChannel extends Model
{
    protected $table = 'crm_wa_channels';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'is_default' => 'boolean', 'templates_synced_at' => 'datetime'];
    }

    public function templates(): HasMany
    {
        return $this->hasMany(WaTemplate::class, 'crm_wa_channel_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'crm_branch_id');
    }
}
