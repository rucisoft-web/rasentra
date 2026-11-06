<?php

namespace App\Http\Controllers;

use App\Domain\Operations\SelfOrderService;
use App\Models\Outlet;
use App\Models\SelfOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Halaman pesan mandiri untuk tamu: dibuka dari QR di meja, tanpa login dan tanpa aplikasi.
 * QR hanya memuat token meja; harga dan menu selalu diambil dari Rasentra Lokal outlet itu.
 */
class SelfOrderController extends Controller
{
    public function __construct(private readonly SelfOrderService $service) {}

    public function show(string $token): View
    {
        $table = $this->service->table($token);
        $outlet = Outlet::findOrFail($table->outlet_id);

        return view('self-order.app', [
            'token' => $token,
            'table' => $table,
            'outletName' => $outlet->restaurant_name ?? $outlet->name,
        ]);
    }

    public function menu(string $token): JsonResponse
    {
        return response()->json($this->service->menu($this->service->table($token)));
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.note' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:60'],
            'guests' => ['nullable', 'integer', 'min:1', 'max:50'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $order = $this->service->submit($this->service->table($token), $data['items'], $data);

        return response()->json(['id' => $order->id, 'status' => $order->status, 'estimated_total' => (string) $order->estimated_total], 201);
    }

    public function status(string $token, SelfOrder $selfOrder): JsonResponse
    {
        $table = $this->service->table($token);
        abort_unless((int) $selfOrder->dining_table_id === (int) $table->id, 404);

        return response()->json($selfOrder->only(['id', 'status', 'reject_reason']));
    }
}
