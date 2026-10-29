<?php

namespace App\Domain\Integration\Sap;

/**
 * Memilih klien SAP per database perusahaan (sap_solo, sap_lombok, ...). Di luar profil
 * central, atau bila instance belum dikonfigurasi, dipakai klien bawaan dari container
 * (FakeSapClient untuk pengembangan dan uji).
 */
final class SapClientFactory
{
    /** @var array<string, SapClient> */
    private array $clients = [];

    public function __construct(private readonly ?SapClient $fixed = null) {}

    public function for(string $companyDb): SapClient
    {
        if ($this->fixed !== null) {
            return $this->fixed;
        }

        $config = self::config($companyDb);
        if (config('rasentra.profile') !== 'central' || empty($config['url'])) {
            return app(SapClient::class);
        }

        return $this->clients[$companyDb] ??= new ServiceLayerClient($config);
    }

    /** Konfigurasi efektif satu instance: nilai bawaan ditimpa nilai khusus perusahaan. */
    public static function config(string $companyDb): array
    {
        $sap = config('services.sap', []);
        $own = array_filter($sap['companies'][$companyDb] ?? [], fn ($v) => $v !== null && $v !== '');

        return ['company_db' => $companyDb] + $own + array_diff_key($sap, ['companies' => true]);
    }
}
