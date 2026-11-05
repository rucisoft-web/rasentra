<?php

namespace App\Providers;

use App\Domain\Integration\Accurate\AccurateClient;
use App\Domain\Integration\Accurate\HttpAccurateClient;
use App\Domain\Integration\Ai\AiClient;
use App\Domain\Integration\Ai\ClaudeAiClient;
use App\Domain\Integration\Sap\FakeSapClient;
use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapClientFactory;
use App\Domain\Integration\WhatsApp\CloudApiClient;
use App\Domain\Integration\WhatsApp\WhatsAppClient;
use App\Domain\Lokal\LokalSettings;
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
        // Rasentra CRM: Accurate Online lewat API Token per perusahaan (diatur di konsol CRM).
        $this->app->singleton(AccurateClient::class, fn () => new HttpAccurateClient);
        $this->app->singleton(WhatsAppClient::class, fn () => new CloudApiClient);
        $this->app->singleton(AiClient::class, fn () => new ClaudeAiClient);
    }

    public function boot(): void
    {
        // Rasentra Lokal: pairing yang diisi dari layar Pemasangan menimpa .env.
        if (config('rasentra.profile') === 'lokal' && ! $this->app->runningUnitTests()) {
            LokalSettings::apply();
        }
    }
}
