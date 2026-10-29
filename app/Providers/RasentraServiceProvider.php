<?php

namespace App\Providers;

use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapClientFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Registrasi layanan per profil. SAP Bridge hanya aktif pada profil central; di outlet
 * tidak ada kredensial SAP sama sekali. Setiap CV memposting ke instance SAP-nya sendiri.
 */
class RasentraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Klien bawaan saat instance SAP belum dikonfigurasi (pengembangan dan uji).
        $this->app->singleton(SapClient::class, fn () => new FakeSapClient);
        $this->app->singleton(SapClientFactory::class, fn () => new SapClientFactory);
    }
}
