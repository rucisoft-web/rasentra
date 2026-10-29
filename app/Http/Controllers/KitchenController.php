<?php

namespace App\Http\Controllers;

use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Inventory\StockLedger;
use App\Domain\Kitchen\KitchenService;
use App\Domain\Recipe\RecipeExploder;
use App\Domain\Recipe\RecipeService;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RecipeVersion;
use App\Models\TicketItem;
use App\Models\Visit;
use App\Support\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Rasentra Kitchen: layar station (KDS), expeditor, antrean pickup, produksi batch dan analisis waktu saji.
 * Station "expo" adalah layar expeditor yang melihat semua station per pesanan.
 */
class KitchenController extends Controller
{
    public function __construct(
        private readonly KitchenBoard $board,
        private readonly KitchenService $kitchen,
    ) {}

    public function home(Request $request): View
    {
        return view('kitchen.home', [
            'outlet' => Outlet::find($request->user()->outlet_id),
            'stations' => config('rasentra.kitchen.stations'),
        ]);
    }

    public function screen(string $station): View
    {
        $this->assertStation($station, allowExpo: true);

        return view('kitchen.board', ['station' => $station, 'stations' => config('rasentra.kitchen.stations')]);
    }

    public function queueScreen(Request $request): View
    {
        return view('kitchen.queue', ['outlet' => Outlet::find($request->user()->outlet_id)]);
    }

    public function data(Request $request, string $station): JsonResponse
    {
        $this->assertStation($station, allowExpo: true);

        return response()->json($this->board->board($request->user()->outlet_id, $station === 'expo' ? null : $station));
    }

    public function bumpTicket(Request $request, KitchenTicket $ticket): JsonResponse
    {
        $this->authorizeOutlet($request, $ticket->outlet_id);
        $this->board->bumpTicket($ticket, $request->user(), $request->boolean('expo'));

        return response()->json(['ok' => true]);
    }

    public function bumpItem(Request $request, TicketItem $item): JsonResponse
    {
        $this->authorizeOutlet($request, $item->ticket->outlet_id);
        $item = $this->board->bumpItem($item, $request->user(), $request->boolean('expo'));

        return response()->json(['ok' => true, 'status' => $item->status]);
    }

    public function scan(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $ticket = $this->board->bumpByCode($request->user()->outlet_id, $data['code'], $request->user(), $request->boolean('expo'));

        return response()->json(['ok' => true, 'ticket' => $ticket->id, 'station' => $ticket->station]);
    }

    public function recall(Request $request, TicketItem $item): JsonResponse
    {
        $this->authorizeOutlet($request, $item->ticket->outlet_id);
        $this->board->recall($item);

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, TicketItem $item): JsonResponse
    {
        $this->authorizeOutlet($request, $item->ticket->outlet_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $this->kitchen->cancel($item, $request->user(), $data['reason']);

        return response()->json(['ok' => true]);
    }

    public function confirmCancel(Request $request, TicketItem $item): JsonResponse
    {
        $this->authorizeOutlet($request, $item->ticket->outlet_id);
        $this->kitchen->confirmCancel($item, $request->user());

        return response()->json(['ok' => true]);
    }

    /** Penanda perubahan papan; layar memuat ulang hanya bila nilainya berubah. */
    public function version(Request $request): JsonResponse
    {
        return response()->json(['v' => $this->board->version($request->user()->outlet_id), 'server_time' => now()->toIso8601String()]);
    }

    /**
     * Tiket dapur cetak (printer 80 mm di station) dengan barcode kode bump. Tanpa parameter,
     * mencetak tiket dari pengiriman terakhir pesanan ini.
     */
    public function printTickets(Request $request, Order $order): View
    {
        $this->authorizeOutlet($request, $order->outlet_id);
        $tickets = KitchenTicket::where('order_id', $order->id)
            ->when($request->query('ticket'), fn ($q, $id) => $q->whereKey($id), function ($q) use ($order) {
                $last = KitchenTicket::where('order_id', $order->id)->max('created_at');
                $q->where('created_at', '>=', Carbon::parse($last)->subSeconds(5));
            })
            ->with('items')->orderBy('station')->get();
        $table = $order->visit_id ? DiningTable::whereKey(Visit::whereKey($order->visit_id)->value('dining_table_id'))->value('code') : null;

        return view('kitchen.print', [
            'order' => $order,
            'tickets' => $tickets,
            'label' => $table ? 'Meja '.$table : ($order->queue_no ? 'No. '.$order->queue_no : ($order->doc_no ?? 'Pesanan '.strtoupper(substr($order->id, -6)))),
            // Tiket susulan untuk station yang sama ditandai TAMBAHAN agar tidak dimasak dobel.
            'additional' => $tickets->filter(fn (KitchenTicket $t) => KitchenTicket::where('order_id', $order->id)->where('station', $t->station)
                ->where('created_at', '<', $t->created_at)->exists())->pluck('id')->all(),
            'stations' => config('rasentra.kitchen.stations'),
            'auto' => $request->boolean('auto'),
        ]);
    }

    /** Layar analisis waktu saji untuk kepala dapur. */
    public function productionScreen(Request $request): View
    {
        return view('kitchen.production', [
            'outlet' => Outlet::find($request->user()->outlet_id),
            'reasons' => config('rasentra.kitchen.production_loss_reasons'),
            'tolerance' => config('rasentra.kitchen.production_loss_tolerance_percent'),
        ]);
    }

    /** Data layar produksi: resep berstok, bahan untuk potong & porsi, stok hasil prep dan riwayat hari ini. */
    public function production(Request $request, RecipeExploder $exploder): JsonResponse
    {
        $outletId = (int) $request->user()->outlet_id;
        $outlet = Outlet::findOrFail($outletId);
        $versions = RecipeVersion::with('recipe.product', 'lines')->where('kind', 'stocked_prep')->where('status', 'approved')->get()
            ->filter(fn (RecipeVersion $v) => $v->outlet_ids === null || in_array($outletId, $v->outlet_ids, true))->values();
        $balance = fn (int $id) => StockLedger::balance($outletId, $id);
        $products = Product::whereIn('type', ['ingredient', 'prep'])->where('is_stocked', true)->orderBy('name')->get(['id', 'code', 'name', 'type', 'uom_code']);
        $names = $products->pluck('name', 'id');
        $since = now($outlet->timezone)->startOfDay()->utc();

        return response()->json([
            'recipes' => $versions->map(fn (RecipeVersion $v) => [
                'version_id' => $v->id, 'version' => $v->version, 'name' => $v->recipe->name, 'product_id' => $v->recipe->product_id,
                'yield' => (string) $v->yield_quantity, 'uom' => $v->yield_uom, 'stock' => $balance($v->recipe->product_id),
                'lines' => collect($exploder->explode($v, (string) $v->yield_quantity))->map(fn ($n) => $n + [
                    'name' => $names[$n['product_id']] ?? Product::whereKey($n['product_id'])->value('name'), 'stock' => $balance($n['product_id']),
                ])->values(),
            ]),
            'products' => $products->map(fn (Product $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'type' => $p->type, 'uom' => $p->uom_code, 'stock' => $balance($p->id)]),
            'batches' => ProductionBatch::with('user:id,name', 'recipeVersion.recipe:id,name')->where('outlet_id', $outletId)->where('produced_at', '>=', $since)
                ->latest('produced_at')->limit(30)->get()->map(fn (ProductionBatch $b) => [
                    'id' => $b->id, 'doc_no' => $b->doc_no, 'kind' => $b->kind, 'title' => $b->recipeVersion?->recipe->name ?? 'Potong & porsi',
                    'at' => $b->produced_at->toIso8601String(), 'user' => $b->user?->name,
                    'inputs' => collect($b->inputs)->map(fn ($l) => ['name' => $names[$l['product_id']] ?? Product::whereKey($l['product_id'])->value('name'), 'quantity' => $l['quantity'], 'uom' => $l['uom'] ?? ''])->values(),
                    'outputs' => collect($b->outputs)->map(fn ($l) => ['name' => $names[$l['product_id']] ?? Product::whereKey($l['product_id'])->value('name'), 'quantity' => $l['quantity'], 'uom' => $l['uom'] ?? ''])->values(),
                    'expected' => $b->expected_quantity !== null ? (string) $b->expected_quantity : null,
                    'loss' => (string) $b->loss_quantity, 'loss_reason' => $b->loss_reason,
                ]),
            'tolerance' => config('rasentra.kitchen.production_loss_tolerance_percent'),
        ]);
    }

    public function storeProduction(Request $request, RecipeService $recipes): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:recipe,cutting',
            'version_id' => 'required_if:mode,recipe|nullable|integer',
            'quantity' => 'required_if:mode,recipe|nullable|numeric|gt:0',
            'output' => 'required_if:mode,recipe|nullable|numeric|gt:0',
            'inputs' => 'array',
            'inputs.*.product_id' => 'required|integer|exists:products,id',
            'inputs.*.quantity' => 'required|numeric|min:0',
            'outputs' => 'required_if:mode,cutting|array',
            'outputs.*.product_id' => 'required|integer|exists:products,id',
            'outputs.*.quantity' => 'required|numeric|gt:0',
            'loss_reason' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:200',
        ]);
        $user = $request->user();
        $num = fn ($v) => (string) $v;

        if ($data['mode'] === 'recipe') {
            $version = RecipeVersion::with('recipe.product')->findOrFail($data['version_id']);
            $actual = collect($data['inputs'] ?? [])->mapWithKeys(fn ($l) => [(int) $l['product_id'] => $num($l['quantity'])])->all();
            $batch = $recipes->produceFromRecipe($user->outlet_id, $user, $version, $num($data['quantity']), $num($data['output']), $actual ?: null, $data['loss_reason'] ?? null, $data['notes'] ?? null);
        } else {
            $inputs = collect($data['inputs'] ?? [])->filter(fn ($l) => (float) $l['quantity'] > 0)->values();
            if ($inputs->isEmpty()) {
                throw new DomainException('quantity_required', 'Isi bahan yang dipotong dan beratnya.', 422);
            }
            $line = fn ($l) => ['product_id' => (int) $l['product_id'], 'quantity' => $num($l['quantity'])];
            $in = $inputs->map($line)->all();
            $tolerance = (string) (array_sum(array_column($in, 'quantity')) * (float) config('rasentra.kitchen.production_loss_tolerance_percent', 2) / 100);
            $batch = $recipes->produce($user->outlet_id, $user, $in, collect($data['outputs'])->map($line)->all(), $data['loss_reason'] ?? null, null, $tolerance, $data['notes'] ?? null);
        }

        return response()->json(['id' => $batch->id, 'doc_no' => $batch->doc_no, 'loss' => (string) $batch->loss_quantity], 201);
    }

    public function analyticsScreen(Request $request): View
    {
        return view('kitchen.analytics', ['outlet' => Outlet::find($request->user()->outlet_id), 'stations' => config('rasentra.kitchen.stations')]);
    }

    public function queue(Request $request): JsonResponse
    {
        return response()->json($this->board->queue($request->user()->outlet_id));
    }

    public function analytics(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        // Rentang tanggal dibaca dalam zona waktu outlet, lalu dibandingkan dalam UTC.
        $tz = config('rasentra.display_timezone');
        $from = Carbon::parse($data['from'] ?? 'today', $tz)->startOfDay()->utc();
        $to = Carbon::parse($data['to'] ?? 'today', $tz)->endOfDay()->utc();

        return response()->json($this->board->analytics($request->user()->outlet_id, $from, $to));
    }

    private function assertStation(string $station, bool $allowExpo = false): void
    {
        if (! ($allowExpo && $station === 'expo') && ! array_key_exists($station, config('rasentra.kitchen.stations'))) {
            throw new DomainException('station_unknown', "Station {$station} tidak dikenal.", 404);
        }
    }

    private function authorizeOutlet(Request $request, ?int $outletId): void
    {
        if ($outletId !== (int) $request->user()->outlet_id) {
            throw new DomainException('forbidden_outlet', 'Tiket bukan milik outlet ini.', 403);
        }
    }
}
