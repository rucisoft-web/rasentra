<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $table = 'crm_campaigns';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['audience' => 'array', 'params' => 'array', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WaChannel::class, 'crm_wa_channel_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WaTemplate::class, 'crm_wa_template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class, 'crm_campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
