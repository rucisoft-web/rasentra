<?php

namespace App\Models\Crm;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $table = 'crm_customers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'first_purchase_on' => 'date',
            'last_purchase_on' => 'date',
            'wa_status_at' => 'datetime',
            'total_spent' => 'decimal:2',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerOutlet::class, 'crm_customer_id');
    }

    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'crm_customer_outlets', 'crm_customer_id', 'outlet_id')
            ->withPivot(['member_code', 'source', 'joined_on'])->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'crm_customer_tag', 'crm_customer_id', 'crm_tag_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'crm_customer_id');
    }
}
