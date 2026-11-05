<?php

namespace App\Domain\Integration\Accurate;

use App\Domain\Integration\Sap\SapClient;
use App\Domain\Integration\Sap\SapTimeout;
use App\Models\BusinessUnit;
use App\Support\Decimal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klien Accurate Online untuk CV yang membukukan di Accurate. Bentuk dokumen dari posting
 * Rasentra Central (Invoices, IncomingPayments, CreditNotes, InventoryGenExits/Entries)
 * diterjemahkan ke Faktur Penjualan, Penerimaan Penjualan, Retur Penjualan dan Penyesuaian
 * Persediaan Accurate. Mapping item, gudang, pelanggan dan akun kas/bank tetap memakai
 * tabel mapping yang sama (kunci = kode database CV), jadi monitoring dan retry di Core sama.
 *
 * Nomor dokumen Accurate diturunkan dari referensi sumber POS, sehingga pemeriksaan
 * "sudah ada?" sebelum posting ulang cukup dengan mencari nomor tersebut.
 *
 * Autentikasi memakai API Token Accurate: header Authorization Bearer, X-Api-Timestamp dan
 * X-Api-Signature (HMAC-SHA256 timestamp dengan Signature Secret, base64). Kredensial diambil dari
 * koneksi Rasentra CRM → Accurate Online untuk CV tersebut, sehingga satu API Token dipakai bersama.
 */
final class AccurateOnlineClient implements SapClient
{
    private const ENDPOINT = [
        'Invoices' => 'sales-invoice',
        'IncomingPayments' => 'sales-receipt',
        'CreditNotes' => 'sales-return',
        'InventoryGenExits' => 'item-adjustment',
        'InventoryGenEntries' => 'item-adjustment',
    ];

    private const PREFIX = [
        'Invoices' => 'RSI', 'IncomingPayments' => 'RSR', 'CreditNotes' => 'RRT', 'InventoryGenExits' => 'RAO', 'InventoryGenEntries' => 'RAI',
    ];

    private ?array $credentials = null;

    public function __construct(private readonly BusinessUnit $company) {}

    /** Token & secret dari koneksi Rasentra CRM → Accurate Online bila ada (lihat BusinessUnit::accurateCredentials). */
    private function credentials(): array
    {
        return $this->credentials ??= $this->company->accurateCredentials();
    }

    /** Nomor dokumen Accurate yang stabil untuk satu sumber POS. */
    public static function number(string $object, string $sourceRef): string
    {
        return (self::PREFIX[$object] ?? 'RPS').'-'.strtoupper(substr(hash('sha256', $object.'|'.$sourceRef), 0, 14));
    }

    public function findBySourceRef(string $object, string $sourceRef): ?array
    {
        $number = self::number($object, $sourceRef);
        $res = $this->http()->get($this->url(self::ENDPOINT[$object].'/detail.do'), ['number' => $number]);
        if ($res->status() === 404 || ! ($res->json('s') ?? false)) {
            return null;
        }
        $id = (int) $res->json('d.id');

        return $id ? ['DocEntry' => $id, 'DocNum' => $id, 'number' => $number] : null;
    }

    public function create(string $object, array $payload): array
    {
        $ref = (string) ($payload['U_POS_SRC'] ?? '');
        $body = match ($object) {
            'Invoices' => $this->invoice($payload, $ref),
            'IncomingPayments' => $this->receipt($payload, $ref),
            'CreditNotes' => $this->salesReturn($payload, $ref),
            'InventoryGenExits', 'InventoryGenEntries' => $this->adjustment($object, $payload, $ref),
            default => throw new RuntimeException("Dokumen {$object} belum didukung untuk Accurate Online."),
        };

        try {
            $res = $this->http()->asForm()->post($this->url(self::ENDPOINT[$object].'/save.do'), $this->flatten($body));
        } catch (ConnectionException $e) {
            throw new SapTimeout('Accurate tidak menjawab: '.$e->getMessage(), 0, $e);
        }
        if (! $res->successful() || ! $res->json('s')) {
            throw new RuntimeException('Accurate menolak dokumen: '.json_encode($res->json('d') ?? $res->body(), JSON_UNESCAPED_UNICODE));
        }
        $id = (int) $res->json('r.id');

        return ['DocEntry' => $id, 'DocNum' => $id];
    }

    public function ping(): array
    {
        $this->http()->timeout(15)->get($this->url('item/list.do'), ['sp.pageSize' => 1])->throw();

        return ['mode' => 'accurate_online'];
    }

    private function invoice(array $p, string $ref): array
    {
        return [
            'customerNo' => $p['CardCode'],
            'transDate' => $this->date($p['DocDate']),
            'number' => self::number('Invoices', $ref),
            'description' => trim('POS '.($p['NumAtCard'] ?? '').' · '.$ref),
            'detailItem' => array_map(fn ($l) => array_filter([
                'itemNo' => $l['ItemCode'],
                'quantity' => $l['Quantity'],
                'unitPrice' => $l['UnitPrice'],
                'itemCashDiscount' => $this->discount($l),
                'warehouseName' => $l['WarehouseCode'] ?? null,
            ], fn ($v) => $v !== null), $p['DocumentLines']),
        ];
    }

    /** Penerimaan penjualan: satu baris pembayaran per akun kas/bank pada satu faktur. */
    private function receipt(array $p, string $ref): array
    {
        $invoiceRef = $ref; // order:{id}, sama dengan referensi faktur
        $accounts = [];
        if (! empty($p['CashSum'])) {
            $accounts[] = ['bankNo' => $p['CashAccount'], 'amount' => $p['CashSum']];
        }
        foreach ($p['TransferAccounts'] ?? [] as $t) {
            $accounts[] = ['bankNo' => $t['Account'], 'amount' => $t['Sum']];
        }
        $main = $accounts[0] ?? ['bankNo' => null, 'amount' => $p['DocTotal']];

        // Accurate menerima satu akun kas/bank per penerimaan; bila pembayaran campuran, akun lain
        // dicatat sebagai baris pembayaran tambahan pada penerimaan yang sama lewat detailInvoice.
        return [
            'bankNo' => $main['bankNo'],
            'transDate' => $this->date($p['DocDate']),
            'number' => self::number('IncomingPayments', $ref),
            'chequeAmount' => $p['DocTotal'],
            'description' => 'Pembayaran POS '.$ref.(count($accounts) > 1 ? ' · '.collect($accounts)->map(fn ($a) => $a['bankNo'].' '.$a['amount'])->implode(', ') : ''),
            'detailInvoice' => [['invoiceNo' => self::number('Invoices', $invoiceRef), 'paymentAmount' => $p['PaymentInvoices'][0]['SumApplied'] ?? $p['DocTotal']]],
        ];
    }

    private function salesReturn(array $p, string $ref): array
    {
        $baseEntry = collect($p['DocumentLines'])->pluck('BaseEntry')->filter()->first();
        $invoiceNo = $baseEntry ? $this->http()->get($this->url('sales-invoice/detail.do'), ['id' => $baseEntry])->json('d.number') : null;

        return array_filter([
            'customerNo' => $p['CardCode'],
            'transDate' => $this->date($p['DocDate']),
            'number' => self::number('CreditNotes', $ref),
            'returnType' => $invoiceNo ? 'INVOICE' : 'NO_INVOICE',
            'invoiceNo' => $invoiceNo,
            'description' => $p['Comments'] ?? null,
            'detailItem' => array_map(fn ($l) => array_filter([
                'itemNo' => $l['ItemCode'],
                'quantity' => $l['Quantity'],
                'unitPrice' => $l['UnitPrice'],
                'itemCashDiscount' => $this->discount($l),
                'warehouseName' => $l['WarehouseCode'] ?? null,
            ], fn ($v) => $v !== null), $p['DocumentLines']),
        ], fn ($v) => $v !== null);
    }

    private function adjustment(string $object, array $p, string $ref): array
    {
        $type = $object === 'InventoryGenExits' ? 'ADJUSTMENT_OUT' : 'ADJUSTMENT_IN';

        return array_filter([
            'transDate' => $this->date($p['DocDate']),
            'number' => self::number($object, $ref),
            'adjustmentAccountNo' => collect($p['DocumentLines'])->pluck('AccountCode')->filter()->first(),
            'description' => $p['Comments'] ?? null,
            'detailItem' => array_map(fn ($l) => array_filter([
                'itemNo' => $l['ItemCode'],
                'quantity' => $l['Quantity'],
                'itemAdjustmentType' => $type,
                'warehouseName' => $l['WarehouseCode'] ?? null,
            ], fn ($v) => $v !== null), $p['DocumentLines']),
        ], fn ($v) => $v !== null);
    }

    private function discount(array $line): ?string
    {
        if (! isset($line['LineTotal'], $line['UnitPrice'], $line['Quantity'])) {
            return null;
        }
        $d = Decimal::of($line['UnitPrice'])->multipliedBy($line['Quantity'])->minus($line['LineTotal']);

        return $d->isPositive() ? Decimal::money($d) : null;
    }

    private function date(string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    /** API Accurate memakai form dengan indeks, mis. detailItem[0].itemNo. */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : (is_int($key) ? "{$prefix}[{$key}]" : "{$prefix}.{$key}");
            if (is_array($value)) {
                $out += $this->flatten($value, $name);
            } else {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }

    private function http(): PendingRequest
    {
        $timestamp = now('Asia/Jakarta')->format('d/m/Y H:i:s');

        return Http::timeout(30)->acceptJson()->withHeaders([
            'Authorization' => 'Bearer '.$this->credentials()['token'],
            'X-Api-Timestamp' => $timestamp,
            'X-Api-Signature' => base64_encode(hash_hmac('sha256', $timestamp, (string) $this->credentials()['secret'], true)),
        ]);
    }

    /** Host database Accurate (mis. https://zeus.accurate.id) dari token; disimpan di cache. */
    private function host(): string
    {
        if (filled($this->credentials()['host'])) {
            return rtrim($this->credentials()['host'], '/');
        }

        return Cache::remember('accurate:host:'.$this->company->id, now()->addHours(12), function () {
            $res = $this->http()->post(config('services.accurate.account_url', 'https://account.accurate.id').'/api/api-token.do')->throw();
            $host = $res->json('d.database.host') ?? $res->json('d.host');
            if (! $host) {
                throw new RuntimeException('Host database Accurate tidak ditemukan dari API Token.');
            }

            return rtrim($host, '/');
        });
    }

    private function url(string $path): string
    {
        return $this->host().'/accurate/api/'.$path;
    }
}
