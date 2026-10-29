<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\ReceivingService;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\StockLedger;
use App\Models\CountSession;
use App\Models\Location;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\PackEvent;
use App\Models\Product;
use App\Support\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rasentra Inventory: aplikasi handheld untuk cek inventory di outlet. Penerimaan kiriman
 * per Pack ID, stok opname blind count dan cek stok per produk atau Pack ID.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly ReceivingService $receiving,
        private readonly StockCountService $counts,
    ) {}

    public function app(Request $request): View
    {
        $outletId = $request->user()->outlet_id;

        return view('inventory.app', [
            'outlet' => Outlet::find($outletId),
            'locations' => Location::where('outlet_id', $outletId)->where('is_active', true)->orderBy('code')->get(['code', 'name']),
        ]);
    }

    /** Cek stok: Pack ID menampilkan status dan riwayat; kode produk menampilkan saldo. */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:64']])['code']);
        $outletId = $request->user()->outlet_id;

        $pack = Pack::where('pack_code', $code)->with('product:id,code,name')->first();
        if ($pack !== null) {
            return response()->json([
                'kind' => 'pack',
                'pack' => $pack->only(['pack_code', 'status', 'net_weight', 'uom_code', 'expires_at', 'packed_at', 'label_price']) + [
                    'product' => $pack->product->name,
                    'own_outlet' => (int) $pack->outlet_id === (int) $outletId,
                    'outlet' => Outlet::whereKey($pack->outlet_id)->value('code'),
                ],
                'events' => PackEvent::where('pack_id', $pack->id)->latest('occurred_at')->limit(10)->get(['event', 'from_status', 'to_status', 'reason', 'occurred_at']),
            ]);
        }

        $product = Product::where('code', $code)->orWhere('barcode', $code)->first();
        if ($product === null) {
            throw new DomainException('code_unknown', "Kode {$code} tidak dikenal.", 404);
        }

        return response()->json([
            'kind' => 'product',
            'product' => $product->only(['code', 'name', 'uom_code', 'is_weighed']),
            'available' => StockLedger::balance($outletId, $product->id),
            'quarantine' => StockLedger::balance($outletId, $product->id, 'quarantine'),
            'packs' => $product->is_weighed
                ? Pack::where('outlet_id', $outletId)->where('product_id', $product->id)->whereIn('status', ['received', 'available', 'reserved', 'hold'])
                    ->orderBy('expires_at')->get(['pack_code', 'net_weight', 'status', 'expires_at'])
                : [],
        ]);
    }

    public function expected(Request $request): JsonResponse
    {
        return response()->json($this->receiving->expected($request->user()->outlet_id)->map(fn ($p) => [
            'pack_code' => $p->pack_code, 'product' => $p->product->name, 'net_weight' => (string) $p->net_weight,
            'uom' => $p->uom_code, 'expires_at' => $p->expires_at,
        ]));
    }

    public function receive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'action' => ['nullable', 'in:accept,reject'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);
        $pack = $this->receiving->receive($request->user()->outlet_id, $data['code'], $request->user(), $data['action'] ?? 'accept', $data['reason'] ?? null);

        return response()->json(['pack_code' => $pack->pack_code, 'status' => $pack->status, 'net_weight' => (string) $pack->net_weight,
            'remaining' => $this->receiving->expected($request->user()->outlet_id)->count()]);
    }

    public function sessions(Request $request): JsonResponse
    {
        return response()->json(CountSession::where('outlet_id', $request->user()->outlet_id)
            ->whereIn('status', ['counting', 'recount', 'review'])->latest()->get(['id', 'type', 'blind', 'status', 'current_round', 'cutoff_at']));
    }

    public function openSession(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['nullable', 'in:full,cycle,special'], 'blind' => ['nullable', 'boolean'], 'threshold' => ['nullable', 'numeric', 'gte:0']]);
        $session = $this->counts->open($request->user(), $request->user()->outlet_id, $data['type'] ?? 'cycle', $data['blind'] ?? true, (string) ($data['threshold'] ?? '0.050'));

        return response()->json($session, 201);
    }

    public function showSession(Request $request, CountSession $session): JsonResponse
    {
        $this->authorizeOutlet($request, $session);

        return response()->json($this->counts->progress($session, $request->user()));
    }

    public function countLine(Request $request, CountSession $session): JsonResponse
    {
        $this->authorizeOutlet($request, $session);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'location' => ['required', 'string', 'max:30'],
        ]);
        $line = $this->counts->record($session, $request->user(), $data['code'], isset($data['quantity']) ? (string) $data['quantity'] : null, $data['location']);

        return response()->json(['id' => $line->id, 'quantity' => (string) $line->quantity, 'round' => $line->round], 201);
    }

    public function review(Request $request, CountSession $session): JsonResponse
    {
        $this->authorizeOutlet($request, $session);

        return response()->json($this->counts->review($session, $request->user()));
    }

    public function approve(Request $request, CountSession $session): JsonResponse
    {
        $this->authorizeOutlet($request, $session);
        $session = $this->counts->approve($session, $request->user());

        return response()->json($session->only(['id', 'status', 'approved_at', 'summary']));
    }

    private function authorizeOutlet(Request $request, CountSession $session): void
    {
        if ((int) $session->outlet_id !== (int) $request->user()->outlet_id) {
            throw new DomainException('forbidden_outlet', 'Sesi opname bukan milik outlet ini.', 403);
        }
    }
}
