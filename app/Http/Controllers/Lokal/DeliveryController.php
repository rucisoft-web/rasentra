<?php

namespace App\Http\Controllers\Lokal;

use App\Domain\Operations\DeliveryService;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Papan delivery order outlet: tugaskan kurir atau mitra, pantau sampai terkirim. */
class DeliveryController extends Controller
{
    use OutletScope;

    public function __construct(private readonly DeliveryService $service) {}

    public function index(Request $request): View
    {
        $outletId = $this->outletId($request);
        $orders = Order::where('outlet_id', $outletId)->where('channel', 'delivery')->whereIn('status', ['paid', 'closed'])
            ->where('finalized_at', '>=', now()->subDays(2))->orderByDesc('finalized_at')->get();
        $deliveries = Delivery::whereIn('order_id', $orders->pluck('id'))->get()->keyBy('order_id');

        return view('lokal.ops.deliveries', [
            'title' => 'Delivery order',
            'orders' => $orders,
            'deliveries' => $deliveries,
            'couriers' => User::where('outlet_id', $outletId)->where('is_active', true)->whereIn('role', ['courier', 'waiter', 'cashier'])->orderBy('name')->get(['id', 'name', 'role']),
            'providers' => $this->service->providers($outletId),
            'status' => DeliveryService::STATUS,
        ]);
    }

    public function assign(Request $request, Order $order): RedirectResponse
    {
        abort_unless((int) $order->outlet_id === $this->outletId($request), 404);
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:20'],
            'courier_id' => ['nullable', 'integer'],
            'courier_name' => ['nullable', 'string', 'max:80'],
            'external_ref' => ['nullable', 'string', 'max:60'],
            'fee' => ['nullable', 'numeric', 'min:0'],
        ]);
        $this->service->assign($this->service->ensure($order), $data, $request->user());

        return back()->with('ok', "Pengiriman {$order->doc_no} ditugaskan.");
    }

    public function status(Request $request, Delivery $delivery): RedirectResponse
    {
        abort_unless((int) $delivery->outlet_id === $this->outletId($request), 404);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(DeliveryService::STATUS))], 'note' => ['nullable', 'string', 'max:200']]);
        $this->service->advance($delivery, $data['status'], $request->user(), $data['note'] ?? null);

        return back()->with('ok', "Status {$delivery->doc_no}: ".DeliveryService::STATUS[$data['status']][0].'.');
    }
}
