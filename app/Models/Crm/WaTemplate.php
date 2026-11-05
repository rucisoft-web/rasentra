<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaTemplate extends Model
{
    protected $table = 'crm_wa_templates';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['components' => 'array'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WaChannel::class, 'crm_wa_channel_id');
    }
}
