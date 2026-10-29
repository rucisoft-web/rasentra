<?php

namespace App\Http\Controllers;

use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Kitchen\KitchenService;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\TicketItem;
use App\Models\Visit;
use App\Support\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Rasentra Kitchen: layar station (KDS), expeditor, antrean pickup dan analisis waktu saji.
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
