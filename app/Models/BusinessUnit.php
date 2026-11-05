<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perusahaan (CV) di dalam Indoguna Group. Setiap CV membukukan ke sistemnya sendiri:
 * SAP Business One (instance SQL Server per CV) atau Accurate Online (database per CV).
 * Kolom sap_company_db menjadi kunci tujuan posting dan mapping untuk keduanya.
 */
class BusinessUnit extends Model
{
    public const ACCOUNTING = ['sap_b1' => 'SAP Business One (SQL Server)', 'accurate' => 'Accurate Online'];

    protected $table = 'business_units';

    protected $guarded = [];

    protected $hidden = ['accurate_api_token', 'accurate_signature_secret'];

    protected function casts(): array
    {
        return [
            'accurate_api_token' => 'encrypted',
            'accurate_signature_secret' => 'encrypted',
        ];
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    public function usesAccurate(): bool
    {
        return $this->accounting_system === 'accurate';
    }
}
