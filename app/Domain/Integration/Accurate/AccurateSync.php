<?php

namespace App\Domain\Integration\Accurate;

use App\Domain\Crm\CustomerDirectory;
use App\Domain\Crm\Phone;
use App\Models\Crm\AccurateConnection;
use App\Models\Crm\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Tarik pelanggan dan faktur penjualan dari Accurate Online ke Rasentra CRM (satu arah,
 * Accurate → CRM). Pelanggan dikenali dari nomor HP; customerNo Accurate disimpan di
 * crm_external_ids agar faktur bisa dikaitkan. Faktur ditarik bertahap mulai dari tanggal
 * terakhir yang sudah sinkron (mundur 3 hari untuk menangkap faktur yang diinput terlambat).
 */
final class AccurateSync
{
    public const INITIAL_DAYS = 365;

    public function __construct(private readonly AccurateClient $client, private readonly CustomerDirectory $directory) {}

    public static function configured(AccurateConnection $conn): bool
    {
        return filled($conn->api_token) && filled($conn->signature_secret);
    }

    /** @return array{customers: int, skipped: int, invoices: int, unmatched: int} */
    public function sync(AccurateConnection $conn): array
    {
        $summary = ['customers' => 0, 'skipped' => 0, 'invoices' => 0, 'unmatched' => 0];
        try {
            if ($conn->sync_customers) {
                $this->pullCustomers($conn, $summary);
            }
            if ($conn->sync_invoices) {
                $this->pullInvoices($conn, $summary);
            }
            $conn->update(['last_synced_at' => now(), 'last_status' => 'ok', 'last_message' => $this->text($summary)]);
        } catch (\Throwable $e) {
            $conn->update(['last_synced_at' => now(), 'last_status' => 'failed', 'last_message' => mb_substr($e->getMessage(), 0, 1000)]);
            throw $e;
        }

        return $summary;
    }

    private function pullCustomers(AccurateConnection $conn, array &$summary): void
    {
        $page = 1;
        do {
            $batch = $this->client->customers($conn, $page);
            foreach ($batch['rows'] as $row) {
                $phone = Phone::normalize($row['phone'] ?? null);
                if ($phone === null || $row['customer_no'] === '') {
                    $summary['skipped']++;

                    continue;
                }
                [$customer] = $this->directory->upsert($phone, [
                    'name' => $row['name'],
                    'email' => filter_var($row['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                    'city' => $row['city'] ?? null,
                    'member_code' => $row['customer_no'],
                ], $conn->default_outlet_id, 'accurate');
                DB::table('crm_external_ids')->updateOrInsert(
                    ['system' => 'accurate', 'scope_id' => $conn->business_unit_id, 'external_id' => $row['customer_no']],
                    ['crm_customer_id' => $customer->id, 'updated_at' => now(), 'created_at' => now()],
                );
                $summary['customers']++;
            }
        } while (++$page <= $batch['page_count']);
    }

    private function pullInvoices(AccurateConnection $conn, array &$summary): void
    {
        $from = $conn->invoices_synced_until?->copy()->subDays(3) ?? now()->subDays(self::INITIAL_DAYS);
        $to = now();
        $owners = DB::table('crm_external_ids')->where('system', 'accurate')->where('scope_id', $conn->business_unit_id)
            ->pluck('crm_customer_id', 'external_id');
        $page = 1;
        do {
            $batch = $this->client->invoices($conn, $from->toDateString(), $to->toDateString(), $page);
            foreach ($batch['rows'] as $inv) {
                $customerId = $owners[$inv['customer_no']] ?? null;
                if ($customerId === null) {
                    $summary['unmatched']++;

                    continue;
                }
                $customer = Customer::find($customerId);
                if ($customer && $this->directory->recordPurchase($customer, 'accurate', $conn->business_unit_id.':'.$inv['id'], $inv['date'], $inv['amount'], $conn->default_outlet_id, $conn->business_unit_id, $inv['number'])) {
                    $summary['invoices']++;
                }
            }
        } while (++$page <= $batch['page_count']);
        $conn->update(['invoices_synced_until' => $to->toDateString()]);
    }

    private function text(array $s): string
    {
        return "{$s['customers']} pelanggan, {$s['invoices']} faktur baru, {$s['skipped']} pelanggan tanpa HP, {$s['unmatched']} faktur tanpa pelanggan";
    }
}
