<?php

namespace App\Providers;

use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapPostingService;
use App\Domain\Integration\Sap\ServiceLayerClient;
use Illuminate\Support\ServiceProvider;

/**
 * Registrasi layanan per profil. SAP Bridge hanya aktif pada profil central; di outlet
 * tidak ada kredensial SAP sama sekali.
 */
class RasentraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SapClient::class, function () {
            $sap = config('services.sap');
            if (config('rasentra.profile') !== 'central' || empty($sap['url'])) {
                return new FakeSapClient;
            }

            return new ServiceLayerClient($sap);
        });

        $this->app->bind(SapPostingService::class, fn ($app) => new SapPostingService(
            $app->make(SapClient::class),
            config('services.sap.company_db') ?: 'PASARTANI',
        ));
    }
}
