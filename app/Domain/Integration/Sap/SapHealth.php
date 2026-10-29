<?php

namespace App\Domain\Integration\Sap;

use App\Models\BusinessUnit;
use App\Support\SystemState;
use Throwable;

/**
 * Rasentra Central: cek koneksi Service Layer per instance SAP B1 (sap_solo, sap_lombok, ...).
 * Hasil terakhir disimpan untuk halaman Rasentra Central di Core.
 */
final class SapHealth
{
    public function __construct(private readonly SapClientFactory $factory) {}

    /** @return array<string, array{ok:bool, mode?:string, ms:int, error?:string, at:string}> */
    public function checkAll(): array
    {
        return BusinessUnit::whereNotNull('sap_company_db')->orderBy('id')->pluck('sap_company_db')
            ->mapWithKeys(fn ($db) => [$db => $this->check($db)])->all();
    }

    public function check(string $companyDb): array
    {
        $started = hrtime(true);
        try {
            $result = ['ok' => true] + $this->factory->for($companyDb)->ping();
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }
        $result += ['ms' => (int) ((hrtime(true) - $started) / 1e6), 'at' => now()->toIso8601ZuluString()];
        SystemState::put("sap:{$companyDb}", $result);

        return $result;
    }

    public static function last(string $companyDb): ?array
    {
        return SystemState::get("sap:{$companyDb}");
    }
}
