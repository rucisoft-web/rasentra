<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    protected $table = 'crm_memberships';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['joined_on' => 'date'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'crm_branch_id');
    }
}
