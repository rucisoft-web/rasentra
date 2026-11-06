<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Community extends Model
{
    protected $table = 'crm_communities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'crm_branch_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'crm_community_members', 'crm_community_id', 'crm_customer_id')->withPivot(['source', 'joined_at']);
    }
}
