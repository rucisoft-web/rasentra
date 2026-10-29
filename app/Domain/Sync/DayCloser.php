<?php

namespace App\Domain\Sync;

use App\Domain\Audit\Audit;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\SalesReturn;
use App\Models\Shift;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Rasentra Lokal: tutup hari bisnis. Ringkasan struk, nilai dan kas shift dikirim ke Rasentra
 * Central lewat outbox (business_day.closed) supaya Central bisa mencocokkan dengan transaksi
 * yang sudah diterimanya. Boleh dijalankan ulang; versi berikutnya menggantikan yang lama.
 */
final class DayCloser
{
    /** Ringkasan hari bisnis seperti yang akan dikirim, untuk pratinjau sebelum ditutup. */
    public function summary(Outlet $outlet, string $businessDate): array
    {
        $orders = DB::table('orders')->where('outlet_id', $outlet->id)->whereDate('business_date', $businessDate)
            ->whereNotNull('doc_no')->whereNotNull('finalized_at')->orderBy('doc_no')
            ->get(['id', 'doc_no', 'channel', 'net_amount', 'gross_amount', 'discount_amount']);
        $shifts = Shift::where('outlet_id', $outlet->id)->whereDate('business_date', $businessDate)->get();
        $payments = Payment::whereIn('order_id', $orders->pluck('id'))->where('status', 'succeeded')->get(['method', 'amount']);
        // Retur dicatat pada tanggal bisnis saat retur dibuat, bukan tanggal struk asalnya.
        $returns = SalesReturn::where('outlet_id', $outlet->id)->whereDate('business_date', $businessDate)->orderBy('doc_no')->get(['doc_no', 'net_amount', 'refund_method']);

        return [
            'outlet_code' => $outlet->code,
            'business_date' => $businessDate,
            'closed_at' => now()->toIso8601ZuluString(),
            'orders' => $orders->count(),
            'gross_amount' => Decimal::money(Decimal::sum($orders->pluck('gross_amount'))),
            'discount_amount' => Decimal::money(Decimal::sum($orders->pluck('discount_amount'))),
            'net_amount' => Decimal::money(Decimal::sum($orders->pluck('net_amount'))),
            'by_channel' => $orders->groupBy('channel')->map(fn ($g) => ['orders' => $g->count(), 'net_amount' => Decimal::money(Decimal::sum($g->pluck('net_amount')))])->all(),
            'payments' => $payments->groupBy('method')->map(fn ($g) => Decimal::money(Decimal::sum($g->pluck('amount'))))->all(),
            'doc_nos' => $orders->pluck('doc_no')->all(),
            'returns' => $returns->count(),
            'returns_net_amount' => Decimal::money(Decimal::sum($returns->pluck('net_amount'))),
            'refunds' => $returns->groupBy('refund_method')->map(fn ($g) => Decimal::money(Decimal::sum($g->pluck('net_amount'))))->all(),
            'return_doc_nos' => $returns->pluck('doc_no')->all(),
            'shifts' => $shifts->map(fn (Shift $s) => [
                'id' => $s->id, 'user_id' => $s->user_id, 'status' => $s->status,
                'cash_difference' => $s->cash_difference !== null ? Decimal::money((string) $s->cash_difference) : null,
            ])->values()->all(),
            'cash_difference' => Decimal::money(Decimal::sum($shifts->pluck('cash_difference')->filter(fn ($v) => $v !== null))),
            'open_shifts' => $shifts->where('status', 'open')->count(),
        ];
    }

    public function close(Outlet $outlet, string $businessDate, ?int $userId = null): OutboxEvent
    {
        return DB::transaction(function () use ($outlet, $businessDate, $userId) {
            $payload = $this->summary($outlet, $businessDate);
            $entityId = self::entityId($outlet, $businessDate);
            $version = OutboxEvent::where('entity_type', 'business_day')->where('entity_id', $entityId)->max('entity_version') + 1;
            $event = Outbox::record($outlet->id, 'business_day', $entityId, $version, 'business_day.closed', $payload, $businessDate);
            Audit::record('business_day.closed', "business_day:{$outlet->code}:{$businessDate}", $userId, outletId: $outlet->id, amount: $payload['net_amount'],
                after: ['orders' => $payload['orders'], 'cash_difference' => $payload['cash_difference'], 'version' => $version]);

            return $event;
        });
    }

    public static function entityId(Outlet $outlet, string $businessDate): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "rasentra:business_day:{$outlet->code}:{$businessDate}")->toString();
    }
}
