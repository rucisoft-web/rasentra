<?php

namespace App\Domain\Inventory;

use App\Domain\Audit\Audit;
use App\Models\Location;
use App\Models\Pack;
use App\Models\PackEvent;
use App\Models\User;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Penerimaan kiriman gudang pusat lewat Rasentra Mobile: scan Pack ID satu per satu.
 * Pack diterima masuk stok outlet; pack rusak/tidak sesuai ditolak ke karantina
 * tanpa menambah stok jual. Scan ulang tidak menambah stok dua kali.
 */
final class ReceivingService
{
    public function receive(int $outletId, string $packCode, User $user, string $action = 'accept', ?string $reason = null): Pack
    {
        return DB::transaction(function () use ($outletId, $packCode, $user, $action, $reason) {
            $pack = Pack::where('pack_code', trim($packCode))->lockForUpdate()->first();
            if ($pack === null) {
                throw new DomainException('pack_unknown', "Pack {$packCode} tidak dikenal.", 404);
            }
            if ((int) $pack->outlet_id !== $outletId) {
                throw DomainException::conflict('pack_other_outlet', "Pack {$packCode} bukan kiriman untuk outlet ini.");
            }
            if (in_array($pack->status, ['received', 'available', 'reserved', 'sold'], true)) {
                throw DomainException::conflict('pack_already_received', "Pack {$packCode} sudah diterima sebelumnya.");
            }
            if (! in_array($pack->status, ['shipped', 'allocated'], true)) {
                throw DomainException::conflict('pack_not_shipped', "Pack {$packCode} berstatus {$pack->status}, tidak bisa diterima.");
            }

            if ($action === 'reject') {
                if (! $reason) {
                    throw new DomainException('reason_required', 'Alasan penolakan wajib diisi.', 422);
                }
                $quarantine = Location::where('outlet_id', $outletId)->where('type', 'quarantine')->value('id');
                StockLedger::move($outletId, $pack->product_id, (string) $pack->net_weight, $pack->uom_code, 'receipt_rejected',
                    'pack_receipt', $pack->id, locationId: $quarantine, packId: $pack->id, state: 'quarantine');
                $this->event($pack, 'received_rejected', 'damaged', $user, $reason);
                $pack->update(['status' => 'damaged', 'location_type' => 'outlet']);
                Audit::record('receiving.rejected', $pack, $user->id, reason: $reason, outletId: $outletId);

                return $pack;
            }

            StockLedger::move($outletId, $pack->product_id, (string) $pack->net_weight, $pack->uom_code, 'receipt',
                'pack_receipt', $pack->id, packId: $pack->id);
            $this->event($pack, 'received', 'received', $user, null);
            $pack->update(['status' => 'received', 'location_type' => 'outlet']);

            return $pack;
        });
    }

    /** Pack dalam perjalanan ke outlet ini (belum discan terima). */
    public function expected(int $outletId)
    {
        return Pack::where('outlet_id', $outletId)->whereIn('status', ['shipped', 'allocated'])
            ->with('product:id,code,name')->orderBy('pack_code')->get(['id', 'pack_code', 'product_id', 'net_weight', 'uom_code', 'expires_at', 'status']);
    }

    private function event(Pack $pack, string $event, string $to, User $user, ?string $reason): void
    {
        PackEvent::create([
            'pack_id' => $pack->id, 'event' => $event, 'from_status' => $pack->status, 'to_status' => $to,
            'actor_id' => $user->id, 'reason' => $reason, 'source_type' => 'pack_receipt', 'source_id' => $pack->id, 'occurred_at' => now(),
        ]);
    }
}
