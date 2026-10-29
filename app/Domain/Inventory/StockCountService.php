<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\Audit;
use App\Domain\Sales\Approval;
use App\Models\CountLine;
use App\Models\CountSession;
use App\Models\Location;
use App\Models\Pack;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Stok opname dengan Rasentra Inventory. Petugas hitung tidak melihat saldo sistem (blind
 * count). Saldo pembanding diambil per waktu cutoff, sehingga penjualan selama
 * penghitungan tidak mengacaukan selisih. Selisih di atas ambang wajib dihitung ulang
 * oleh petugas yang berbeda sebelum kepala outlet menyetujui dan penyesuaian diposting.
 */
final class StockCountService
{
    public const OPENER_ROLES = ['outlet_head', 'admin', 'storekeeper'];

    public function open(User $user, int $outletId, string $type = 'cycle', bool $blind = true, string $threshold = '0.050'): CountSession
    {
        if (! in_array($user->role, self::OPENER_ROLES, true)) {
            throw new DomainException('forbidden_role', 'Hanya kepala outlet atau petugas gudang yang dapat membuat sesi opname.', 403);
        }

        $session = CountSession::create([
            'outlet_id' => $outletId,
            'type' => $type,
            'blind' => $blind,
            'cutoff_at' => now(),
            'recount_threshold' => Decimal::qty($threshold),
            'status' => 'counting',
            'current_round' => 1,
            'created_by' => $user->id,
        ]);
        Audit::record('count.opened', $session, $user->id, outletId: $outletId);

        return $session;
    }

    /**
     * Catat satu hasil scan. Pack ID dihitung sebagai berat bersihnya; SKU biasa wajib
     * menyertakan kuantitas. Kode lokasi (QR rak/chiller) dicatat bersama baris.
     */
    public function record(CountSession $session, User $user, string $code, ?string $quantity, string $locationCode): CountLine
    {
        return DB::transaction(function () use ($session, $user, $code, $quantity, $locationCode) {
            $session = CountSession::lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['counting', 'recount'], true)) {
                throw DomainException::conflict('count_closed', 'Sesi opname tidak sedang menghitung.');
            }
            $location = Location::where('outlet_id', $session->outlet_id)->where('code', $locationCode)->first();
            if ($location === null) {
                throw new DomainException('location_unknown', "Lokasi {$locationCode} tidak dikenal. Scan QR lokasi dulu.", 404);
            }

            $code = trim($code);
            $pack = Pack::where('pack_code', $code)->first();
            if ($pack !== null) {
                if ((int) $pack->outlet_id !== (int) $session->outlet_id) {
                    throw DomainException::conflict('pack_other_outlet', "Pack {$code} tercatat milik outlet lain. Pisahkan dan laporkan.");
                }
                $productId = $pack->product_id;
                $qty = (string) $pack->net_weight;
            } else {
                $product = Product::where('code', $code)->orWhere('barcode', $code)->first();
                if ($product === null) {
                    throw new DomainException('code_unknown', "Kode {$code} tidak dikenal.", 404);
                }
                if ($product->is_weighed) {
                    throw new DomainException('scan_pack', 'Produk timbang dihitung per Pack ID. Scan label kemasan.', 422);
                }
                if ($quantity === null || ! Decimal::of($quantity)->isPositive()) {
                    throw new DomainException('quantity_required', 'Isi jumlah hitungan.', 422);
                }
                $productId = $product->id;
                $qty = $quantity;
            }

            $round = $session->current_round;
            if ($round > 1) {
                if (! in_array($productId, $session->recount_product_ids ?? [], true)) {
                    throw DomainException::conflict('not_in_recount', 'Produk ini tidak termasuk daftar hitung ulang.');
                }
                $firstCounters = CountLine::where('count_session_id', $session->id)->where('round', 1)->where('product_id', $productId)->pluck('counted_by');
                if ($firstCounters->contains($user->id)) {
                    throw DomainException::conflict('recount_same_counter', 'Hitung ulang harus oleh petugas yang berbeda dari hitungan pertama.');
                }
            }

            if ($pack !== null && CountLine::where('count_session_id', $session->id)->where('round', $round)->where('pack_id', $pack->id)->exists()) {
                throw DomainException::conflict('pack_already_counted', "Pack {$code} sudah dihitung di ronde ini.");
            }

            return CountLine::create([
                'count_session_id' => $session->id,
                'round' => $round,
                'location_id' => $location->id,
                'product_id' => $productId,
                'pack_id' => $pack?->id,
                'quantity' => Decimal::qty($qty),
                'counted_by' => $user->id,
            ]);
        });
    }

    /** Progres untuk petugas hitung. Pada blind count tidak memuat saldo sistem. */
    public function progress(CountSession $session, User $user): array
    {
        $lines = CountLine::where('count_session_id', $session->id)->where('round', $session->current_round)
            ->with('product:id,code,name,uom_code')->latest()->get();

        return [
            'session' => $session->only(['id', 'type', 'blind', 'status', 'current_round', 'cutoff_at']),
            'recount_products' => $session->current_round > 1
                ? Product::whereIn('id', $session->recount_product_ids ?? [])->get(['id', 'code', 'name'])
                : [],
            'my_lines' => $lines->where('counted_by', $user->id)->take(30)->map(fn ($l) => [
                'id' => $l->id,
                'product' => $l->product->name,
                'quantity' => (string) $l->quantity,
                'uom' => $l->product->uom_code,
                'pack' => $l->pack_id ? Pack::whereKey($l->pack_id)->value('pack_code') : null,
            ])->values(),
            'total_lines' => $lines->count(),
        ];
    }

    /**
     * Bandingkan hitungan dengan saldo sistem per cutoff. Ronde pertama dengan selisih di
     * atas ambang memicu hitung ulang; setelah hitung ulang sesi siap disetujui.
     */
    public function review(CountSession $session, User $user): array
    {
        $this->assertApproverRole($user);

        return DB::transaction(function () use ($session) {
            $session = CountSession::lockForUpdate()->findOrFail($session->id);
            if (! in_array($session->status, ['counting', 'recount', 'review'], true)) {
                throw DomainException::conflict('count_closed', 'Sesi opname sudah ditutup.');
            }

            $report = $this->report($session);
            $flagged = collect($report)->where('flagged', true)->pluck('product_id')->all();

            if ($session->current_round === 1 && $flagged !== []) {
                $session->update(['status' => 'recount', 'current_round' => 2, 'recount_product_ids' => $flagged, 'summary' => $report]);
            } else {
                $session->update(['status' => 'review', 'summary' => $report]);
            }

            return ['session' => $session->fresh()->only(['id', 'status', 'current_round', 'recount_product_ids']), 'lines' => $report];
        });
    }

    /** Persetujuan kepala outlet: posting penyesuaian stok sebesar selisih final. */
    public function approve(CountSession $session, User $approver): CountSession
    {
        $this->assertApproverRole($approver);

        return DB::transaction(function () use ($session, $approver) {
            $session = CountSession::lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'review') {
                throw DomainException::conflict('count_not_reviewed', 'Review hasil hitung (dan hitung ulang bila diminta) sebelum menyetujui.');
            }
            if ((int) $approver->outlet_id !== (int) $session->outlet_id && $approver->role !== 'admin') {
                throw new DomainException('forbidden_outlet', 'Penyetuju bukan dari outlet ini.', 403);
            }

            $report = $this->report($session);
            foreach (array_values($report) as $i => $line) {
                if (Decimal::of($line['variance'])->isZero()) {
                    continue;
                }
                StockLedger::move($session->outlet_id, $line['product_id'], $line['variance'], $line['uom'], 'count_adjustment',
                    'count_session', $session->id, sourceLineNo: $i + 1);
            }

            $session->update(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now(), 'summary' => $report]);
            Audit::record('count.approved', $session, $approver->id, approverId: $approver->id, outletId: $session->outlet_id,
                after: ['adjusted_products' => collect($report)->reject(fn ($l) => Decimal::of($l['variance'])->isZero())->count()]);

            return $session;
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function report(CountSession $session): array
    {
        $lines = CountLine::where('count_session_id', $session->id)->get();
        $productIds = $lines->pluck('product_id')->unique();
        if ($session->type === 'full') {
            // Opname penuh: produk bersaldo yang tidak dihitung sama sekali dianggap nol.
            $productIds = $productIds->merge(
                StockMovement::where('outlet_id', $session->outlet_id)->where('stock_state', 'available')
                    ->where('occurred_at', '<=', $session->cutoff_at)
                    ->groupBy('product_id')->havingRaw('SUM(quantity) <> 0')->pluck('product_id')
            )->unique();
        }

        $threshold = Decimal::of($session->recount_threshold);
        $report = [];
        foreach (Product::whereIn('id', $productIds)->orderBy('name')->get() as $product) {
            $own = $lines->where('product_id', $product->id);
            $round = (int) ($own->max('round') ?? 1);
            $counted = Decimal::sum($own->where('round', $round)->pluck('quantity'));
            $system = Decimal::sum(StockMovement::where('outlet_id', $session->outlet_id)->where('product_id', $product->id)
                ->where('stock_state', 'available')->where('occurred_at', '<=', $session->cutoff_at)->pluck('quantity'));
            $variance = $counted->minus($system);

            $countedPacks = $own->where('round', $round)->pluck('pack_id')->filter();
            $missing = $product->is_weighed
                ? Pack::where('outlet_id', $session->outlet_id)->where('product_id', $product->id)->whereIn('status', ['received', 'available', 'reserved', 'hold'])
                    ->whereNotIn('id', $countedPacks)->orderBy('pack_code')->pluck('pack_code')->all()
                : [];

            $report[] = [
                'product_id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'uom' => $product->uom_code,
                'round' => $round,
                'system' => Decimal::qty($system),
                'counted' => Decimal::qty($counted),
                'variance' => Decimal::qty($variance),
                'flagged' => $variance->abs()->isGreaterThan($threshold),
                'missing_packs' => $missing,
            ];
        }

        return $report;
    }

    private function assertApproverRole(User $user): void
    {
        if (! in_array($user->role, Approval::APPROVER_ROLES, true)) {
            throw new DomainException('forbidden_role', 'Review dan persetujuan opname hanya oleh kepala outlet.', 403);
        }
    }
}
