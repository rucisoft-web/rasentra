<?php

namespace App\Domain\Operations;

use App\Domain\Audit\Audit;
use App\Domain\Settings\Settings;
use App\Domain\Sync\Outbox;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Delivery order: pesanan kanal delivery yang sudah dibayar diteruskan ke kurir internal
 * atau mitra (GoFood, GrabFood, ShopeeFood). Status pengiriman dicatat per tahap dan dikirim
 * ke Central agar pusat bisa memantau ketepatan waktu kirim seluruh outlet.
 */
final class DeliveryService
{
    public const STATUS = [
        'pending' => ['Menunggu kurir', 'warn'],
        'assigned' => ['Kurir ditugaskan', 'info'],
        'on_the_way' => ['Dalam perjalanan', 'info'],
        'delivered' => ['Terkirim', 'ok'],
        'failed' => ['Gagal kirim', 'err'],
    ];

    /** Urutan tahap yang sah. */
    private const NEXT = [
        'pending' => ['assigned', 'failed'],
        'assigned' => ['on_the_way', 'pending', 'failed'],
        'on_the_way' => ['delivered', 'failed'],
        'delivered' => [],
        'failed' => ['pending'],
    ];

    public function __construct(private readonly Settings $settings) {}

    public function providers(int $outletId): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->settings->get('delivery.providers', $outletId)))));
    }

    /** Buat catatan pengiriman untuk pesanan delivery yang sudah final (idempoten). */
    public function ensure(Order $order): Delivery
    {
        if ($order->channel !== 'delivery') {
            throw new DomainException('not_delivery', 'Pesanan ini bukan kanal delivery.', 422);
        }

        return Delivery::firstOrCreate(['order_id' => $order->id], [
            'id' => (string) Str::uuid7(),
            'outlet_id' => $order->outlet_id,
            'doc_no' => $order->doc_no,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'address' => $order->delivery_address,
            'order_amount' => $order->net_amount,
            'fee' => Decimal::money((string) $this->settings->get('delivery.default_fee', $order->outlet_id)),
            'business_date' => $order->business_date->toDateString(),
            'promised_at' => $order->promised_at,
            'status' => 'pending',
            'version' => 1,
        ]);
    }

    public function assign(Delivery $delivery, array $data, User $actor): Delivery
    {
        $provider = $data['provider'] ?? 'internal';
        if (! in_array($provider, $this->providers($delivery->outlet_id), true)) {
            throw new DomainException('provider_invalid', 'Mitra pengiriman tidak dikenal.', 422);
        }
        $courier = ! empty($data['courier_id']) ? User::where('outlet_id', $delivery->outlet_id)->find($data['courier_id']) : null;
        if ($provider === 'internal' && $courier === null) {
            throw new DomainException('courier_required', 'Pilih kurir internal.', 422);
        }

        return $this->change($delivery, 'assigned', $actor, [
            'provider' => $provider,
            'courier_id' => $courier?->id,
            'courier_name' => $courier?->name ?? ($data['courier_name'] ?? null),
            'external_ref' => $data['external_ref'] ?? null,
            'fee' => Decimal::money((string) ($data['fee'] ?? $delivery->fee)),
            'assigned_at' => now(),
        ]);
    }

    public function advance(Delivery $delivery, string $status, User $actor, ?string $note = null): Delivery
    {
        if ($status === 'failed' && blank($note)) {
            throw new DomainException('reason_required', 'Isi alasan gagal kirim.', 422);
        }
        $extra = match ($status) {
            'on_the_way' => ['departed_at' => now()],
            'delivered' => ['delivered_at' => now()],
            'pending' => ['courier_id' => null, 'courier_name' => null, 'assigned_at' => null],
            default => [],
        };

        return $this->change($delivery, $status, $actor, $extra + ($note !== null ? ['note' => mb_substr($note, 0, 200)] : []));
    }

    private function change(Delivery $delivery, string $status, User $actor, array $attrs): Delivery
    {
        return DB::transaction(function () use ($delivery, $status, $actor, $attrs) {
            $delivery = Delivery::lockForUpdate()->findOrFail($delivery->id);
            if ($status !== 'assigned' || $delivery->status !== 'assigned') {
                if (! in_array($status, self::NEXT[$delivery->status], true)) {
                    throw DomainException::conflict('delivery_status', 'Status pengiriman tidak bisa diubah dari "'.self::STATUS[$delivery->status][0].'" ke "'.self::STATUS[$status][0].'".');
                }
            }
            $delivery->update($attrs + ['status' => $status, 'version' => $delivery->version + 1]);
            Outbox::record($delivery->outlet_id, 'delivery', $delivery->id, $delivery->version, 'delivery.updated',
                $delivery->fresh()->toArray(), $delivery->business_date->toDateString());
            Audit::record('delivery.'.$status, $delivery, $actor->id, after: ['provider' => $delivery->provider, 'courier' => $delivery->courier_name], outletId: $delivery->outlet_id);

            return $delivery;
        });
    }
}
