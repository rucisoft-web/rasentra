<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $table = 'crm_conversations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_inbound_at' => 'datetime', 'ai_suggested_at' => 'datetime', 'lead_scored_at' => 'datetime', 'ai_handoff' => 'boolean', 'ai_confidence' => 'float', 'lead_value' => 'decimal:2'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'crm_customer_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(WaChannel::class, 'crm_wa_channel_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'crm_conversation_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
