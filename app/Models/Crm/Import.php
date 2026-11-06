<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    protected $table = 'crm_imports';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'crm_branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
