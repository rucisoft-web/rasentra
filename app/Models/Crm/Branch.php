<?php

namespace App\Models\Crm;

use App\Models\BusinessUnit;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cabang di Rasentra CRM: cermin outlet Rasentra, atau outlet CV yang memakai POS lain. */
class Branch extends Model
{
    public const POS_SYSTEMS = ['rasentra' => 'Rasentra POS', 'rene' => 'Rene POS', 'raptor' => 'Raptor', 'lainnya' => 'POS lain'];

    protected $table = 'crm_branches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** Cabang CRM untuk satu outlet Rasentra; dibuat otomatis bila belum ada. */
    public static function forOutlet(int $outletId): self
    {
        $existing = self::where('outlet_id', $outletId)->first();
        if ($existing) {
            return $existing;
        }
        $o = Outlet::findOrFail($outletId);

        return self::firstOrCreate(['outlet_id' => $o->id], [
            'business_unit_id' => $o->business_unit_id,
            'code' => self::where('code', $o->code)->exists() ? $o->code.'-'.$o->id : $o->code,
            'name' => $o->name,
            'pos_system' => 'rasentra',
            'is_active' => (bool) $o->is_active,
        ]);
    }

    /** Pastikan setiap outlet Rasentra punya cabang CRM. */
    public static function syncFromOutlets(): void
    {
        Outlet::whereNotIn('id', self::whereNotNull('outlet_id')->select('outlet_id'))->pluck('id')
            ->each(fn ($id) => self::forOutlet((int) $id));
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'crm_branch_id');
    }
}
