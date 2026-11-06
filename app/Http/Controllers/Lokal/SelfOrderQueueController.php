<?php

namespace App\Http\Controllers\Lokal;

use App\Domain\Operations\SelfOrderService;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\SelfOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Antrean pesanan mandiri dari QR meja untuk pelayan, kasir dan kepala outlet. */
class SelfOrderQueueController extends Controller
{
    use OutletScope;

    public function __construct(private readonly SelfOrderService $service) {}

    public function index(Request $request): View
    {
        $outletId = $this->outletId($request);

        return view('lokal.ops.self-orders', [
            'title' => 'Pesanan mandiri (QR meja)',
            'pending' => SelfOrder::where('outlet_id', $outletId)->where('status', 'submitted')->orderBy('created_at')->get(),
            'recent' => SelfOrder::where('outlet_id', $outletId)->where('status', '!=', 'submitted')->latest('handled_at')->limit(20)->get(),
            'tables' => DiningTable::where('outlet_id', $outletId)->pluck('code', 'id'),
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        return response()->json(['pending' => SelfOrder::where('outlet_id', $this->outletId($request))->where('status', 'submitted')->count()]);
    }

    public function accept(Request $request, SelfOrder $selfOrder): RedirectResponse
    {
        abort_unless((int) $selfOrder->outlet_id === $this->outletId($request), 404);
        $this->service->accept($selfOrder, $request->user());

        return back()->with('ok', 'Pesanan diterima dan masuk ke meja.');
    }

    public function reject(Request $request, SelfOrder $selfOrder): RedirectResponse
    {
        abort_unless((int) $selfOrder->outlet_id === $this->outletId($request), 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:120']]);
        $this->service->reject($selfOrder, $request->user(), $data['reason']);

        return back()->with('ok', 'Pesanan ditolak.');
    }
}
