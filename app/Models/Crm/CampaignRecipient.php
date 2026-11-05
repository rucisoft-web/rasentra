<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    protected $table = 'crm_campaign_recipients';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'crm_customer_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'crm_campaign_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'crm_message_id');
    }
}
