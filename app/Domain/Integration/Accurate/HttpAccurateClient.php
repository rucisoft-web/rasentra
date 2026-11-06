<?php

namespace App\Domain\Integration\Accurate;

use App\Models\Crm\AccurateConnection;
use App\Support\DomainException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Klien Accurate Online dengan API Token (dibuat di Accurate Online → Pengaturan → API Token).
 * Setiap permintaan ditandatangani: X-Api-Timestamp dan X-Api-Signature = base64(HMAC-SHA256
 * (timestamp, signature secret)). Host database diambil sekali dari api-token.do lalu di-cache.
 */
final class HttpAccurateClient implements AccurateClient
{
    private const ACCOUNT_URL = 'https://account.accurate.id/api/api-token.do';

    private const PAGE_SIZE = 100;

    public function customers(AccurateConnection $conn, int $page): array
    {
        $d = $this->get($conn, '/accurate/api/customer/list.json', [
            'fields' => 'id,customerNo,name,mobilePhone,workPhone,email,billCity',
            'sp.page' => $page,
            'sp.pageSize' => self::PAGE_SIZE,
        ]);

        return [
            'rows' => array_map(fn ($c) => [
                'id' => (string) ($c['id'] ?? ''),
                'customer_no' => (string) ($c['customerNo'] ?? $c['id'] ?? ''),
                'name' => (string) ($c['name'] ?? ''),
                'phone' => $c['mobilePhone'] ?? $c['workPhone'] ?? null,
                'email' => $c['email'] ?? null,
                'city' => $c['billCity'] ?? null,
            ], $d['d'] ?? []),
            'page_count' => (int) ($d['sp']['pageCount'] ?? 1),
        ];
    }

    public function invoices(AccurateConnection $conn, string $from, string $to, int $page): array
    {
        $d = $this->get($conn, '/accurate/api/sales-invoice/list.json', [
            'fields' => 'id,number,transDate,totalAmount,customer',
            'filter.transDate.op' => 'BETWEEN',
            'filter.transDate.val[0]' => Carbon::parse($from)->format('d/m/Y'),
            'filter.transDate.val[1]' => Carbon::parse($to)->format('d/m/Y'),
            'sp.page' => $page,
            'sp.pageSize' => self::PAGE_SIZE,
        ]);

        return [
            'rows' => array_map(fn ($i) => [
                'id' => (string) ($i['id'] ?? $i['number']),
                'number' => (string) ($i['number'] ?? ''),
                'date' => Carbon::createFromFormat('d/m/Y', (string) $i['transDate'])->toDateString(),
                'amount' => (string) ($i['totalAmount'] ?? '0'),
                'customer_no' => (string) ($i['customer']['customerNo'] ?? $i['customer']['id'] ?? ''),
            ], $d['d'] ?? []),
            'page_count' => (int) ($d['sp']['pageCount'] ?? 1),
        ];
    }

    public function ping(AccurateConnection $conn): string
    {
        return (string) ($this->session($conn, fresh: true)['name'] ?? 'Accurate');
    }

    private function get(AccurateConnection $conn, string $path, array $query): array
    {
        $host = $this->session($conn)['host'];
        $res = $this->signed($conn)->get(rtrim($host, '/').$path, $query);
        $body = $res->json() ?? [];
        if (! $res->successful() || ($body['s'] ?? false) !== true) {
            throw new DomainException('accurate_error', 'Accurate menolak permintaan: '.$this->message($body, $res->status()), 502);
        }

        return $body;
    }

    /** @return array{host: string, name: ?string} */
    private function session(AccurateConnection $conn, bool $fresh = false): array
    {
        $key = 'accurate.host.'.$conn->id.'.'.substr(hash('sha256', (string) $conn->api_token), 0, 12);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHours(12), function () use ($conn) {
            $res = $this->signed($conn)->post(self::ACCOUNT_URL);
            $body = $res->json() ?? [];
            $db = $body['d']['database'] ?? $body['d'] ?? [];
            if (! $res->successful() || ($body['s'] ?? false) !== true || empty($db['host'])) {
                throw new DomainException('accurate_auth', 'API Token Accurate tidak valid: '.$this->message($body, $res->status()), 502);
            }

            return ['host' => $db['host'], 'name' => $db['alias'] ?? $db['name'] ?? null];
        });
    }

    private function signed(AccurateConnection $conn): PendingRequest
    {
        $timestamp = now()->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s');

        return Http::timeout(30)->retry(2, 500, throw: false)->acceptJson()->withHeaders([
            'Authorization' => 'Bearer '.$conn->api_token,
            'X-Api-Timestamp' => $timestamp,
            'X-Api-Signature' => base64_encode(hash_hmac('sha256', $timestamp, (string) $conn->signature_secret, true)),
        ]);
    }

    private function message(array $body, int $status): string
    {
        $d = $body['d'] ?? null;

        return is_array($d) ? implode('; ', array_map('strval', array_filter($d, 'is_scalar'))) : (string) ($d ?: "HTTP {$status}");
    }
}
