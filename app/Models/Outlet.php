<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Outlet extends Model
{
    protected $table = 'outlets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'has_retail' => 'boolean',
            'has_restaurant' => 'boolean',
            'has_cooking_fee' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /** Database SAP B1 milik CV pemilik outlet ini. */
    public static function sapCompanyDb(int $outletId): ?string
    {
        return static::query()->join('business_units', 'business_units.id', '=', 'outlets.business_unit_id')
            ->where('outlets.id', $outletId)->value('business_units.sap_company_db');
    }

    /** Tanggal bisnis menurut zona waktu outlet (Lombok memakai WITA, Solo WIB). */
    public static function businessDate(int $outletId): string
    {
        $tz = static::whereKey($outletId)->value('timezone') ?: config('app.timezone');

        return Carbon::now($tz)->toDateString();
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
