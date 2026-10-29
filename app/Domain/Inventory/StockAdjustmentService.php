<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\Audit;
use App\Domain\Sales\Approval;
use App\Domain\Sync\Outbox;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\PackEvent;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rasentra Inventory: keputusan atas barang karantina dan barang rusak.
 *
 * - Karantina berisi pack ditolak saat terima, retur pelanggan dan barang rusak. Petugas memeriksa,
 *   lalu barang dilepas kembali ke stok jual atau dibuang (waste).
 * - Waste dari stok jual (mis. pack di etalase yang berubah warna) dan pack hampir kedaluwarsa.
 *
 * Setiap keputusan butuh kepala outlet (langsung, atau PIN bila dilakukan petugas gudang), menjadi
 * dokumen penyesuaian stok, dan dikirim ke Rasentra Central sebagai event stock.adjusted.
 */
final class StockAdjustmentService
{
    public const PACK_IN_QUARANTINE = ['quarantine', 'damaged'];

    public const PACK_ON_HAND = ['received', 'available', 'hold'];

    /** Isi karantina outlet: pack per kode dan barang non-pack per produk. */
    public function quarantine(int $outletId): array
    {
        $packs = Pack::where('outlet_id', $outletId)->whereIn('status', self::PACK_IN_QUARANTINE)->with('product:id,code,name')
            ->orderBy('expires_at')->get();
        $reasons = PackEvent::whereIn('pack_id', $packs->pluck('id'))->whereIn('to_status', self::PACK_IN_QUARANTINE)
            ->orderBy('occurred_at')->get()->keyBy('pack_id');

        $loose = StockMovement::where('outlet_id', $outletId)->where('stock_state', 'quarantine')->whereNull('pack_id')
            ->groupBy('product_id', 'uom_code')->selectRaw('product_id, uom_code, SUM(quantity) as quantity')->get()
            ->filter(fn ($r) => Decimal::of((string) $r->quantity)->isPositive());
        $products = Product::whereIn('id', $loose->pluck('product_id'))->get(['id', 'code', 'name'])->keyBy('id');

        return [
            'packs' => $packs->map(fn (Pack $p) => [
                'pack_code' => $p->pack_code, 'product' => $p->product->name, 'net_weight' => (string) $p->net_weight, 'uom' => $p->uom_code,
                'status' => $p->status, 'expires_at' => $p->expires_at?->toDateString(),
                'reason' => $reasons[$p->id]->reason ?? null, 'since' => $reasons[$p->id]->occurred_at ?? null,
            ])->values()->all(),
            'items' => $loose->map(fn ($r) => [
                'code' => $products[$r->product_id]->code, 'product' => $products[$r->product_id]->name,
                'quantity' => Decimal::qty((string) $r->quantity), 'uom' => $r->uom_code,
            ])->values()->all(),
        ];
    }

    /** Pack di outlet yang kedaluwarsa dalam N hari (termasuk yang sudah lewat), urut paling dekat. */
    public function expiring(int $outletId, ?int $days = null): Collection
    {
        $days ??= (int) config('rasentra.inventory.expiry_warning_days', 2);
        $limit = now(Outlet::find($outletId)?->timezone)->addDays($days)->toDateString();

        return Pack::where('outlet_id', $outletId)->whereIn('status', self::PACK_ON_HAND)->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $limit)->with('product:id,name')->orderBy('expires_at')->get();
    }

    /**
     * Buang barang: pack (utuh, sesuai berat label) atau SKU dengan jumlah. Sumbernya karantina
     * atau stok jual, mengikuti status pack; untuk SKU dipilih lewat $from.
     */
    public function waste(int $outletId, User $user, string $code, ?string $quantity, string $reason, ?array $approval, string $from = 'available'): StockAdjustment
    {
        return $this->decide('waste', $outletId, $user, $code, $quantity, $reason, $approval, $from);
    }

    /** Lepas barang karantina yang sudah diperiksa kembali ke stok jual. */
    public function release(int $outletId, User $user, string $code, ?string $quantity, string $reason, ?array $approval): StockAdjustment
    {
        return $this->decide('release', $outletId, $user, $code, $quantity, $reason, $approval, 'quarantine');
    }

    /** Tahan pack (mis. hampir kedaluwarsa atau perlu dicek QC): tidak bisa dijual tanpa PIN kepala outlet. */
    public function hold(int $outletId, User $user, string $code, string $reason): Pack
    {
        return DB::transaction(function () use ($outletId, $user, $code, $reason) {
            $pack = $this->pack($outletId, $code);
            if (! in_array($pack->status, ['received', 'available'], true)) {
                throw DomainException::conflict('pack_not_holdable', "Pack {$code} berstatus {$pack->status}, tidak bisa ditahan.");
            }
            $this->packEvent($pack, 'hold', 'hold', $user, $reason);
            $pack->update(['status' => 'hold']);
            Audit::record('pack.hold', $pack, $user->id, reason: $reason, outletId: $outletId);

            return $pack;
        });
    }

    /**
     * Catat dokumen penyesuaian untuk pergerakan yang sudah dibukukan pemanggil (opname, batal masak).
     *
     * @param  list<array{product_id:int, pack_id?:?string, quantity:string, uom:string, from_state:?string, to_state:?string}>  $lines
     */
    public function record(string $kind, int $outletId, ?User $user, ?int $approverId, ?string $reason, array $lines, ?string $sourceType = null, ?string $sourceId = null): ?StockAdjustment
    {
        if ($lines === []) {
            return null;
        }
        $date = Outlet::businessDate($outletId);
        $outlet = Outlet::lockForUpdate()->findOrFail($outletId);
        $adjustment = StockAdjustment::create([
            'outlet_id' => $outletId, 'doc_no' => $this->nextDocNo($outlet, $kind, $date), 'kind' => $kind, 'reason' => $reason,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'user_id' => $user?->id, 'approved_by' => $approverId, 'business_date' => $date,
        ]);
        foreach (array_values($lines) as $i => $l) {
            StockAdjustmentLine::create([
                'stock_adjustment_id' => $adjustment->id, 'line_no' => $i + 1, 'product_id' => $l['product_id'], 'pack_id' => $l['pack_id'] ?? null,
                'quantity' => Decimal::qty(Decimal::of($l['quantity'])->abs()), 'uom_code' => $l['uom'],
                'from_state' => $l['from_state'], 'to_state' => $l['to_state'],
            ]);
        }
        $adjustment->load('lines');
        Outbox::record($outletId, 'stock_adjustment', $adjustment->id, 1, 'stock.adjusted', $this->payload($adjustment), $date);

        return $adjustment;
    }

    public function payload(StockAdjustment $a): array
    {
        return [
            'id' => $a->id,
            'doc_no' => $a->doc_no,
            'outlet_id' => $a->outlet_id,
            'kind' => $a->kind,
            'reason' => $a->reason,
            'source' => $a->source_type ? ['type' => $a->source_type, 'id' => $a->source_id] : null,
            'business_date' => $a->business_date->toDateString(),
            'lines' => $a->lines->map(fn (StockAdjustmentLine $l) => [
                'line_no' => $l->line_no, 'product_id' => $l->product_id, 'pack_id' => $l->pack_id, 'quantity' => (string) $l->quantity,
                'uom' => $l->uom_code, 'from_state' => $l->from_state, 'to_state' => $l->to_state,
            ])->all(),
        ];
    }

    private function decide(string $kind, int $outletId, User $user, string $code, ?string $quantity, string $reason, ?array $approval, string $from): StockAdjustment
    {
        if (trim($reason) === '') {
            throw new DomainException('reason_required', 'Alasan wajib diisi.', 422);
        }

        return DB::transaction(function () use ($kind, $outletId, $user, $code, $quantity, $reason, $approval, $from) {
            $approver = $this->approver($user, $approval, $outletId);
            $quarantineLoc = Location::where('outlet_id', $outletId)->where('type', 'quarantine')->value('id');
            $date = Outlet::businessDate($outletId);

            $pack = Pack::lockForUpdate()->where('pack_code', $code)->first();
            if ($pack !== null) {
                if ((int) $pack->outlet_id !== $outletId) {
                    throw DomainException::conflict('pack_other_outlet', "Pack {$code} bukan milik outlet ini.");
                }
                $inQuarantine = in_array($pack->status, self::PACK_IN_QUARANTINE, true);
                if ($kind === 'release' && ! $inQuarantine) {
                    throw DomainException::conflict('pack_not_quarantined', "Pack {$code} tidak sedang di karantina (status {$pack->status}).");
                }
                if ($kind === 'waste' && ! $inQuarantine && ! in_array($pack->status, self::PACK_ON_HAND, true)) {
                    throw DomainException::conflict('pack_not_wasteable', "Pack {$code} berstatus {$pack->status}, tidak bisa dibuang.");
                }
                $product = Product::findOrFail($pack->product_id);
                $qty = (string) $pack->net_weight;
                $state = $inQuarantine ? 'quarantine' : 'available';
            } else {
                $product = Product::where('code', $code)->orWhere('barcode', $code)->first()
                    ?? throw new DomainException('code_unknown', "Kode {$code} tidak dikenal.", 404);
                if ($product->is_weighed) {
                    throw new DomainException('pack_required', 'Produk timbang diproses per Pack ID. Scan label pack-nya.', 422);
                }
                $state = $kind === 'release' ? 'quarantine' : $from;
                $qty = $quantity;
                if ($qty === null || ! Decimal::of($qty)->isPositive()) {
                    throw new DomainException('quantity_required', 'Isi jumlah barang.', 422);
                }
                $balance = StockLedger::balance($outletId, $product->id, $state);
                if (Decimal::of($qty)->isGreaterThan(Decimal::of($balance))) {
                    $label = $state === 'quarantine' ? 'karantina' : 'stok';
                    throw DomainException::conflict('quantity_exceeds', "Jumlah melebihi saldo {$label} {$product->name} ({$balance} {$product->uom_code}).");
                }
            }

            $uom = $pack->uom_code ?? $product->uom_code;
            $doc = $this->record($kind, $outletId, $user, $approver->id, $reason, [[
                'product_id' => $product->id, 'pack_id' => $pack?->id, 'quantity' => $qty, 'uom' => $uom,
                'from_state' => $state, 'to_state' => $kind === 'release' ? 'available' : null,
            ]]);

            $out = Decimal::qty(Decimal::of($qty)->negated());
            StockLedger::move($outletId, $product->id, $out, $uom, $kind === 'release' ? 'quarantine_release' : 'waste', 'stock_adjustment', $doc->id, 1,
                locationId: $state === 'quarantine' ? $quarantineLoc : null, packId: $pack?->id, state: $state, businessDate: $date);
            if ($kind === 'release') {
                StockLedger::move($outletId, $product->id, Decimal::qty($qty), $uom, 'quarantine_release', 'stock_adjustment', $doc->id, 2,
                    packId: $pack?->id, businessDate: $date);
            }
            if ($pack !== null) {
                $to = $kind === 'release' ? 'received' : 'wasted';
                $this->packEvent($pack, $kind === 'release' ? 'released' : 'wasted', $to, $user, $reason, $doc->id);
                $pack->update(['status' => $to, 'reserved_order_id' => null]);
            }
            Audit::record($kind === 'release' ? 'stock.released' : 'stock.wasted', $doc, $user->id, approverId: $approver->id, reason: $reason, outletId: $outletId,
                after: ['doc_no' => $doc->doc_no, 'code' => $code, 'quantity' => $qty, 'from' => $state]);

            return $doc->fresh('lines');
        });
    }

    /** Kepala outlet memutuskan sendiri; petugas gudang butuh PIN kepala outlet. */
    private function approver(User $user, ?array $approval, int $outletId): User
    {
        if (in_array($user->role, Approval::APPROVER_ROLES, true) && ((int) $user->outlet_id === $outletId || $user->role === 'admin')) {
            return $user;
        }

        return Approval::verify($approval, $user->id, $outletId);
    }

    private function pack(int $outletId, string $code): Pack
    {
        $pack = Pack::lockForUpdate()->where('pack_code', $code)->first()
            ?? throw new DomainException('pack_unknown', "Pack {$code} tidak dikenal.", 404);
        if ((int) $pack->outlet_id !== $outletId) {
            throw DomainException::conflict('pack_other_outlet', "Pack {$code} bukan milik outlet ini.");
        }

        return $pack;
    }

    private function packEvent(Pack $pack, string $event, string $to, User $user, ?string $reason, ?string $sourceId = null): void
    {
        PackEvent::create([
            'pack_id' => $pack->id, 'event' => $event, 'from_status' => $pack->status, 'to_status' => $to, 'actor_id' => $user->id,
            'reason' => $reason, 'source_type' => $sourceId ? 'stock_adjustment' : null, 'source_id' => $sourceId, 'occurred_at' => now(),
        ]);
    }

    private function nextDocNo(Outlet $outlet, string $kind, string $date): string
    {
        $prefix = $outlet->code.'-'.['waste' => 'W', 'count' => 'OP', 'release' => 'RL', 'production' => 'PR'][$kind].date('ymd', strtotime($date)).'-';
        $last = StockAdjustment::where('outlet_id', $outlet->id)->where('doc_no', 'like', $prefix.'%')->max('doc_no');
        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
