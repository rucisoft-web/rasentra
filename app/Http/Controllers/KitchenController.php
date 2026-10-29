<?php

namespace App\Http\Controllers;

use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Kitchen\KitchenService;
use App\Models\KitchenTicket;
use App\Models\Outlet;
use App\Models\TicketItem;
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

    public function queue(Request $request): JsonResponse
    {
        return response()->json($this->board->queue($request->user()->outlet_id));
    }

    public function analytics(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

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
