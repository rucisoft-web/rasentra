<?php

namespace App\Domain\Crm;

use App\Models\Crm\Customer;
use App\Models\Crm\CustomerOutlet;
use App\Models\Crm\Purchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu masuk data pelanggan: impor member, pesanan POS dan Accurate Online.
 * Pelanggan dikenali dari nomor HP yang dinormalkan. Data yang sudah ada tidak ditimpa
 * dengan nilai kosong; nama hanya diganti bila nama lama masih kosong atau berupa nomor.
 */
final class CustomerDirectory
{
    /**
     * @param  array{name?: ?string, email?: ?string, birth_date?: ?string, city?: ?string, member_code?: ?string, joined_on?: ?string}  $data
     * @return array{0: Customer, 1: bool} pelanggan dan apakah baru dibuat
     */
    public function upsert(string $phone, array $data, ?int $outletId, string $source): array
    {
        return DB::transaction(function () use ($phone, $data, $outletId, $source) {
            $customer = Customer::where('phone', $phone)->lockForUpdate()->first();
            $created = $customer === null;
            $name = trim((string) ($data['name'] ?? ''));

            if ($created) {
                $customer = Customer::create([
                    'phone' => $phone,
                    'name' => $name !== '' ? mb_substr($name, 0, 120) : Phone::display($phone),
                ] + $this->optional($data));
            } else {
                $fill = array_filter($this->optional($data), fn ($v, $k) => $customer->{$k} === null, ARRAY_FILTER_USE_BOTH);
                if ($name !== '' && ($customer->name === '' || preg_match('/^[\d\-\s+]+$/', $customer->name))) {
                    $fill['name'] = mb_substr($name, 0, 120);
                }
                if ($fill) {
                    $customer->update($fill);
                }
            }

            if ($outletId !== null) {
                $membership = CustomerOutlet::firstOrNew(['crm_customer_id' => $customer->id, 'outlet_id' => $outletId]);
                if (! $membership->exists) {
                    $membership->source = $source;
                    $membership->joined_on = $data['joined_on'] ?? now()->toDateString();
                }
                if (! empty($data['member_code']) && $membership->member_code === null) {
                    $membership->member_code = mb_substr((string) $data['member_code'], 0, 40);
                }
                $membership->save();
            }

            return [$customer, $created];
        });
    }

    /** Catat satu transaksi sumber (idempoten per source + source_ref) lalu hitung ulang ringkasan belanja. */
    public function recordPurchase(Customer $customer, string $source, string $ref, string $date, string $amount, ?int $outletId = null, ?int $businessUnitId = null, ?string $docNo = null): bool
    {
        $purchase = Purchase::firstOrCreate(
            ['source' => $source, 'source_ref' => $ref],
            [
                'crm_customer_id' => $customer->id,
                'outlet_id' => $outletId,
                'business_unit_id' => $businessUnitId,
                'purchased_on' => Carbon::parse($date)->toDateString(),
                'amount' => $amount,
                'doc_no' => $docNo,
            ],
        );
        if ($purchase->wasRecentlyCreated) {
            $this->refreshStats($customer);
        }

        return $purchase->wasRecentlyCreated;
    }

    public function refreshStats(Customer $customer): void
    {
        $s = Purchase::where('crm_customer_id', $customer->id)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total, MIN(purchased_on) AS first_on, MAX(purchased_on) AS last_on')
            ->first();
        $customer->update([
            'purchase_count' => (int) $s->n,
            'total_spent' => $s->total,
            'first_purchase_on' => $s->first_on,
            'last_purchase_on' => $s->last_on,
        ]);
    }

    /** Pesanan POS yang sudah final dari payload order.finalized di Rasentra Central. */
    public function recordPosOrder(array $order, int $outletId): void
    {
        $phone = Phone::normalize($order['customer']['phone'] ?? null);
        if ($phone === null) {
            return;
        }
        [$customer] = $this->upsert($phone, ['name' => $order['customer']['name'] ?? null], $outletId, 'pos');
        $this->recordPurchase($customer, 'pos', (string) $order['id'], $order['business_date'], (string) $order['net_amount'], $outletId, docNo: $order['doc_no'] ?? null);
    }

    private function optional(array $data): array
    {
        $out = [];
        foreach (['email' => 120, 'city' => 80] as $key => $len) {
            $v = trim((string) ($data[$key] ?? ''));
            if ($v !== '') {
                $out[$key] = mb_substr($v, 0, $len);
            }
        }
        if (! empty($data['birth_date'])) {
            $out['birth_date'] = $data['birth_date'];
        }

        return $out;
    }
}
