<?php

namespace App\Domain\Integration\Sap;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Klien SAP Business One Service Layer. Hanya berjalan di Rasentra Central; kredensial
 * tidak pernah dikirim ke outlet atau kasir.
 */
final class ServiceLayerClient implements SapClient
{
    public function __construct(private readonly array $config) {}

    public function findBySourceRef(string $object, string $sourceRef): ?array
    {
        $ref = str_replace("'", "''", $sourceRef);
        if ($object === 'JournalEntries') {
            // Jurnal memakai JdtNum/Number, bukan DocEntry/DocNum.
            $row = $this->http()->get($this->url($object), ['$filter' => "U_POS_SRC eq '{$ref}'", '$select' => 'JdtNum,Number'])->throw()->json('value.0');

            return $row ? ['DocEntry' => (int) $row['JdtNum'], 'DocNum' => (int) ($row['Number'] ?? $row['JdtNum'])] : null;
        }
        $rows = $this->http()->get($this->url($object), ['$filter' => "U_POS_SRC eq '{$ref}'", '$select' => 'DocEntry,DocNum,DocTotal'])
            ->throw()->json('value', []);

        return $rows[0] ?? null;
    }

    public function create(string $object, array $payload): array
    {
        if ($object === 'JournalEntries') {
            unset($payload['DocTotal']); // bukan field jurnal SAP
        }
        $response = $this->http()->post($this->url($object), $payload)->throw()->json();
        if ($object === 'JournalEntries') {
            return ['DocEntry' => (int) $response['JdtNum'], 'DocNum' => (int) ($response['Number'] ?? $response['JdtNum'])];
        }

        return ['DocEntry' => (int) $response['DocEntry'], 'DocNum' => (int) $response['DocNum']];
    }

    public function ping(): array
    {
        $this->http()->timeout(15)->get($this->url('Warehouses'), ['$top' => 1, '$select' => 'WarehouseCode'])->throw();

        return ['mode' => 'service_layer'];
    }

    private function http(): PendingRequest
    {
        $session = Cache::remember('sap.b1session.'.$this->config['company_db'], 25 * 60, function () {
            $res = Http::withOptions(['verify' => $this->config['verify'] ?? true])
                ->post(rtrim($this->config['url'], '/').'/Login', [
                    'CompanyDB' => $this->config['company_db'],
                    'UserName' => $this->config['username'],
                    'Password' => $this->config['password'],
                ])->throw();

            return $res->cookies()->getCookieByName('B1SESSION')?->getValue();
        });

        return Http::withOptions(['verify' => $this->config['verify'] ?? true])
            ->withHeaders(['Cookie' => "B1SESSION={$session}"])
            ->timeout(60)->acceptJson();
    }

    private function url(string $object): string
    {
        return rtrim($this->config['url'], '/').'/'.$object;
    }
}
