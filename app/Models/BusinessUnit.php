<?php

namespace App\Models;

use App\Models\Crm\AccurateConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

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

    /**
     * Kredensial Accurate untuk posting. Koneksi yang sudah dibuat di Rasentra CRM → Accurate Online
     * dipakai lebih dulu agar satu CV tidak punya dua API Token; kolom di perusahaan hanya cadangan.
     *
     * @return array{token: ?string, secret: ?string, host: ?string}
     */
    public function accurateCredentials(): array
    {
        $conn = $this->id && Schema::hasTable('crm_accurate_connections') ? AccurateConnection::where('business_unit_id', $this->id)->first() : null;
        if ($conn && filled($conn->api_token) && filled($conn->signature_secret)) {
            return ['token' => $conn->api_token, 'secret' => $conn->signature_secret, 'host' => $this->accurate_host];
        }

        return ['token' => $this->accurate_api_token, 'secret' => $this->accurate_signature_secret, 'host' => $this->accurate_host];
    }

    public function hasAccurateCredentials(): bool
    {
        $c = $this->accurateCredentials();

        return filled($c['token']) && filled($c['secret']);
    }
}
