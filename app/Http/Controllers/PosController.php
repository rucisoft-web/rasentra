<?php

namespace App\Http\Controllers;

use App\Domain\Cash\ShiftService;
use App\Domain\Catalog\PriceResolver;
use App\Domain\CookingService\CookingService;
use App\Domain\Kitchen\KitchenService;
use App\Domain\Sales\Approval;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\PaymentService;
use App\Domain\Sales\ReturnService;
use App\Domain\Sales\TableService;
use App\Models\CookingFeeRule;
use App\Models\CookingJob;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Outlet;
use App\Models\Pack;
use App\Models\Payment;
use App\Models\PriceItem;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Models\Visit;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API lokal untuk layar sentuh Rasentra POS. Berjalan di Rasentra Lokal (server outlet) melalui LAN dengan
 * sesi login petugas. Semua aturan bisnis ada di layanan domain, controller hanya memetakan.
 */
class PosController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly ShiftService $shifts,
        private readonly CookingService $cooking,
        private readonly KitchenService $kitchen,
        private readonly ReturnService $returns,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $outlet = Outlet::with('region')->findOrFail($user->outlet_id);

        return response()->json([
            'user' => $user->only(['id', 'name', 'role']),
            'outlet' => $outlet->only(['id', 'code', 'name', 'has_restaurant', 'has_cooking_fee']) + ['region' => $outlet->region->name, 'print_kitchen_tickets' => (bool) config('rasentra.kitchen.print_tickets')],
            'shift' => Shift::where('user_id', $user->id)->where('status', 'open')->first(),
            'categories' => Product::where('is_active', true)->whereNotNull('category')
                ->whereIn('id', PriceItem::query()->join('price_versions as v', 'v.id', '=', 'price_items.price_version_id')
                    ->where('v.status', 'published')->where(fn ($q) => $q->whereNull('v.outlet_id')->orWhere('v.outlet_id', $outlet->id))
                    ->select('price_items.product_id'))
                ->distinct()->orderBy('category')->pluck('category'),
            'held' => Order::where('outlet_id', $outlet->id)->where('status', 'held')->latest()->limit(20)->get(['id', 'net_amount', 'created_at', 'channel']),
            'cooking_methods' => $outlet->has_cooking_fee ? CookingFeeRule::query()
                ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))
                ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->get(['method', 'basis', 'rate', 'minimum_fee'])->unique('method')->values() : [],
            'approvers' => User::where('outlet_id', $outlet->id)->where('is_active', true)
                ->whereIn('role', Approval::APPROVER_ROLES)->where('id', '!=', $user->id)->get(['id', 'name']),
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $outletId = $request->user()->outlet_id;
        $products = Product::query()
            ->where('is_active', true)
            ->whereIn('type', ['retail', 'menu', 'weighed'])
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "{$s}%")->orWhere('barcode', $s)))
            ->orderBy('name')->limit(120)->get();

        $prices = app(PriceResolver::class);

        // Hanya produk yang punya harga berlaku di outlet ini yang tampil di layar kasir.
        return response()->json($products->map(function (Product $p) use ($prices, $outletId) {
            try {
                $price = $prices->resolve($p->id, $outletId)['unit_price'];
            } catch (DomainException) {
                $price = null;
            }

            return $p->only(['id', 'code', 'name', 'type', 'uom_code', 'is_weighed', 'category']) + [
                'price' => $price,
                'available_packs' => $p->is_weighed ? Pack::where('product_id', $p->id)->where('outlet_id', $outletId)->where('status', 'received')->count() : null,
            ];
        })->filter(fn ($p) => $p['price'] !== null)->values());
    }

    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate(['channel' => ['nullable', 'in:retail,dine_in,takeaway,pickup,delivery']]);
        $user = $request->user();
        $shift = Shift::where('user_id', $user->id)->where('status', 'open')->first();
        if ($shift === null) {
            throw new DomainException('shift_required', 'Buka shift terlebih dahulu.');
        }
        $order = $this->orders->create($user, $user->outlet_id, $data['channel'] ?? 'retail', ['shift_id' => $shift->id]);

        return $this->orderResponse($order, 201);
    }

    public function showOrder(Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);

        return $this->orderResponse($order);
    }

    /** Satu kolom scan untuk barcode SKU dan Pack ID. */
    public function scan(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'approval' => ['nullable', 'array']]);
        $code = trim($data['code']);

        if (Pack::where('pack_code', $code)->exists()) {
            $this->orders->addPack($order, $code, $request->user(), $data['approval'] ?? null);
        } else {
            $product = Product::where('barcode', $code)->orWhere('code', $code)->first();
            if ($product === null) {
                throw new DomainException('code_unknown', "Kode {$code} tidak dikenal.", 404);
            }
            $this->orders->addSku($order, $product);
        }

        return $this->orderResponse($order);
    }

    public function addItem(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'modifiers' => ['nullable', 'array'],
            'note' => ['nullable', 'string', 'max:200'],
            'course' => ['nullable', 'in:starter,main,dessert'],
        ]);
        $this->orders->addSku($order, Product::findOrFail($data['product_id']), (string) ($data['quantity'] ?? '1'), array_filter([
            'modifiers' => $data['modifiers'] ?? null, 'note' => $data['note'] ?? null, 'course' => $data['course'] ?? null,
        ]));

        return $this->orderResponse($order);
    }

    public function voidLine(Request $request, OrderLine $line): JsonResponse
    {
        $this->authorizeOutlet($line->order);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200'], 'approval' => ['nullable', 'array']]);
        $this->orders->voidLine($line, $request->user(), $data['reason'] ?? null, $data['approval'] ?? null);

        return $this->orderResponse($line->order);
    }

    public function overridePrice(Request $request, OrderLine $line): JsonResponse
    {
        $this->authorizeOutlet($line->order);
        $data = $request->validate(['unit_price' => ['required', 'numeric', 'gte:0'], 'reason' => ['required', 'string', 'max:200'], 'approval' => ['required', 'array']]);
        $this->orders->overridePrice($line, (string) $data['unit_price'], $data['reason'], $request->user(), $data['approval']);

        return $this->orderResponse($line->order);
    }

    public function hold(Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $this->orders->hold($order);

        return $this->orderResponse($order);
    }

    public function resume(Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $this->orders->resume($order);

        return $this->orderResponse($order);
    }

    public function pay(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $data = $request->validate([
            'method' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'tendered' => ['nullable', 'numeric', 'gt:0'],
            'provider_ref' => ['nullable', 'string', 'max:80'],
        ]);
        $this->payments->add($order, $data['method'], (string) $data['amount'], [
            'tendered' => isset($data['tendered']) ? (string) $data['tendered'] : null,
            'provider_ref' => $data['provider_ref'] ?? null,
            'provider' => $data['method'] === 'edc_manual' ? 'edc_manual' : null,
        ]);

        return $this->orderResponse($order);
    }

    /** Cek status pembayaran yang belum pasti. Di produksi memanggil adapter provider. */
    public function paymentStatus(Payment $payment): JsonResponse
    {
        $this->authorizeOutlet(Order::findOrFail($payment->order_id));

        return response()->json($payment->fresh());
    }

    public function finalize(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $order = $this->orders->finalize($order, $request->user());

        return response()->json($this->orderPayload($order->fresh()) + ['receipt_url' => route('pos.receipt', $order)]);
    }

    public function cooking(Request $request, OrderLine $line): JsonResponse
    {
        $this->authorizeOutlet($line->order);
        $data = $request->validate([
            'portions' => ['nullable', 'integer', 'min:1', 'max:20'],
            'method' => ['nullable', 'string', 'max:30'],
            'doneness' => ['nullable', 'string', 'max:20'],
            'sauce' => ['nullable', 'string', 'max:40'],
            'allergy_note' => ['nullable', 'string', 'max:200'],
        ]);
        $this->cooking->requestForPackLine($line, $request->user(), $data);

        return $this->orderResponse($line->order);
    }

    public function cookingFromReceipt(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $data = $request->validate([
            'receipt_no' => ['required', 'string'],
            'pack_code' => ['required', 'string'],
            'portions' => ['nullable', 'integer', 'min:1'],
            'doneness' => ['nullable', 'string'],
            'sauce' => ['nullable', 'string'],
            'allergy_note' => ['nullable', 'string'],
        ]);
        $this->cooking->requestFromReceipt($order, $data['receipt_no'], $data['pack_code'], $request->user(), $data);

        return $this->orderResponse($order);
    }

    public function sendToKitchen(Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $tickets = $this->kitchen->send($order);

        return response()->json(['tickets' => count($tickets)] + $this->orderPayload($order->fresh()));
    }

    /** Meja yang sedang terisi; yang minta bayar di atas. Kasir mengambil pesanannya untuk dibayar. */
    public function tables(Request $request): JsonResponse
    {
        $tables = collect(app(TableService::class)->map($request->user()->outlet_id))->whereNotNull('visit');

        return response()->json($tables->map(fn ($t) => [
            'code' => $t['code'], 'area' => $t['area'], 'status' => $t['status'], 'guests' => $t['visit']['guests'],
            'waiter' => $t['visit']['waiter'], 'net_amount' => $t['visit']['net_amount'], 'unsent' => $t['visit']['unsent'],
            'order_id' => Order::where('visit_id', Visit::where('visit_token', $t['visit']['token'])->value('id'))->whereIn('status', ['draft', 'held', 'confirmed'])->value('id'),
        ])->sortBy(fn ($t) => ($t['status'] === 'billing' ? '0' : '1').$t['code'])->values());
    }

    /** Pesanan meja masuk ke shift kasir yang menerima pembayarannya. */
    public function takeTableOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        if ($order->visit_id === null || ! $order->isEditable()) {
            throw DomainException::conflict('not_table_order', 'Pesanan ini bukan pesanan meja yang masih terbuka.');
        }
        $shift = Shift::where('user_id', $request->user()->id)->where('status', 'open')->first();
        if ($shift === null) {
            throw new DomainException('shift_required', 'Buka shift terlebih dahulu.');
        }
        $order->update(['shift_id' => $shift->id]);

        return $this->orderResponse($order);
    }

    /** Data pemesan untuk kanal pickup dan delivery. */
    public function customer(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:80'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'delivery_address' => [$order->channel === 'delivery' ? 'required' : 'nullable', 'string', 'max:300'],
            'promised_at' => ['nullable', 'date'],
            'fulfilment_note' => ['nullable', 'string', 'max:200'],
        ]);
        $this->orders->setCustomer($order, $data);

        return $this->orderResponse($order);
    }

    /** Riwayat struk final outlet ini: hari ini, atau cari nomor struk / nama pemesan. */
    public function history(Request $request): JsonResponse
    {
        $outletId = $request->user()->outlet_id;
        $q = trim((string) $request->query('q', ''));
        $date = $request->query('date') ?: Outlet::businessDate($outletId);

        $orders = Order::query()->with('user:id,name')->withSum('returns', 'net_amount')
            ->where('outlet_id', $outletId)->whereNotNull('finalized_at')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('doc_no', 'like', "%{$q}%")->orWhere('customer_name', 'like', "%{$q}%")->orWhere('customer_phone', 'like', "%{$q}%")))
            ->when($q === '', fn ($w) => $w->whereDate('business_date', $date))
            ->latest('finalized_at')->limit(60)->get();

        return response()->json([
            'date' => $date,
            'orders' => $orders->map(fn (Order $o) => $o->only(['id', 'doc_no', 'channel', 'net_amount', 'customer_name', 'print_count']) + [
                'finalized_at' => $o->finalized_at->toIso8601String(),
                'cashier' => $o->user?->name,
                'returned' => Decimal::money($o->returns_sum_net_amount ?? '0'),
            ])->values(),
        ]);
    }

    /** Detail struk final untuk cetak ulang dan retur: sisa yang bisa diretur per baris. */
    public function orderDetail(Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        if (! $order->isFinal()) {
            throw DomainException::conflict('order_not_final', 'Transaksi ini belum selesai.');
        }
        $payload = $this->orderPayload($order->fresh());
        $lines = $order->lines()->where('status', 'active')->get()->keyBy('id');
        $payload['lines'] = collect($payload['lines'])->map(fn ($l) => $l + [
            'returned_quantity' => (string) $lines[$l['id']]->returned_quantity,
            'returnable_quantity' => Decimal::qty(Decimal::of($lines[$l['id']]->quantity)->minus(Decimal::of($lines[$l['id']]->returned_quantity))),
            'restockable' => $l['line_type'] === 'retail_product' && $l['pack_code'] === null,
        ])->values();
        $days = (int) config('rasentra.pos.return_days', 7);

        return response()->json($payload + [
            'customer' => $order->only(['customer_name', 'customer_phone', 'delivery_address', 'promised_at', 'fulfilment_note']),
            'print_count' => $order->print_count,
            'cashier' => $order->user?->name,
            'returns' => $order->returns()->orderBy('doc_no')->get(['id', 'doc_no', 'net_amount', 'refund_method', 'reason', 'created_at']),
            'refundable' => $this->returns->refundable($order),
            'return_until' => $order->business_date->copy()->addDays($days)->toDateString(),
            'can_return' => $order->business_date->copy()->addDays($days)->toDateString() >= Outlet::businessDate($order->outlet_id),
        ]);
    }

    public function createReturn(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOutlet($order);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_line_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.restock' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:200'],
            'refund.method' => ['required', 'in:cash,qris,edc,edc_manual,voucher'],
            'refund.ref' => ['nullable', 'string', 'max:80'],
            'approval' => ['nullable', 'array'],
        ]);
        $items = array_map(fn ($i) => ['order_line_id' => $i['order_line_id'], 'quantity' => (string) $i['quantity'], 'restock' => $i['restock'] ?? true], $data['items']);
        $return = $this->returns->create($order, $request->user(), $items, $data['reason'], $data['refund'], $data['approval'] ?? null);

        return response()->json([
            'return' => $return->only(['id', 'doc_no', 'net_amount', 'refund_method']),
            'receipt_url' => route('pos.receipt.return', $return),
        ], 201);
    }

    public function openShift(Request $request): JsonResponse
    {
        $data = $request->validate(['opening_cash' => ['required', 'numeric', 'gte:0']]);
        $shift = $this->shifts->open($request->user(), $request->user()->outlet_id, (string) $data['opening_cash']);

        return response()->json($shift, 201);
    }

    public function cashMovement(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:cash_in,cash_out,no_sale_open'],
            'amount' => ['nullable', 'numeric', 'gte:0'],
            'reason' => ['required', 'string', 'max:200'],
        ]);
        $shift = Shift::where('user_id', $request->user()->id)->where('status', 'open')->firstOrFail();
        $this->shifts->cashMovement($shift, $request->user(), $data['type'], (string) ($data['amount'] ?? '0'), $data['reason']);

        return response()->json(['expected' => $this->shifts->expected($shift)]);
    }

    public function shiftSummary(Request $request): JsonResponse
    {
        $shift = Shift::where('user_id', $request->user()->id)->where('status', 'open')->firstOrFail();

        return response()->json(['shift' => $shift, 'expected' => $this->shifts->expected($shift)]);
    }

    public function closeShift(Request $request): JsonResponse
    {
        $data = $request->validate(['counts' => ['required', 'array'], 'approval' => ['nullable', 'array']]);
        $shift = Shift::where('user_id', $request->user()->id)->where('status', 'open')->firstOrFail();

        $shift = $this->shifts->close($shift, $request->user(), $data['counts'], $data['approval'] ?? null);

        return response()->json($shift->toArray() + ['report_url' => route('pos.shift-report', $shift)]);
    }

    private function authorizeOutlet(Order $order): void
    {
        if ((int) $order->outlet_id !== (int) request()->user()->outlet_id) {
            throw new DomainException('outlet_forbidden', 'Transaksi ini milik outlet lain.', 403);
        }
    }

    private function orderResponse(Order $order, int $status = 200): JsonResponse
    {
        return response()->json($this->orderPayload($order->fresh()), $status);
    }

    private function orderPayload(Order $order): array
    {
        $order->load(['lines' => fn ($q) => $q->where('status', 'active')->with(['product:id,name,uom_code', 'pack:id,pack_code']), 'payments']);
        $jobs = CookingJob::where('order_id', $order->id)->get()->keyBy('id');
        $paid = Decimal::sum($order->payments->where('status', 'succeeded')->pluck('amount'));

        return [
            'order' => $order->only(['id', 'doc_no', 'channel', 'status', 'payment_status', 'gross_amount', 'discount_amount', 'net_amount', 'finalized_at',
                'customer_name', 'customer_phone', 'delivery_address', 'promised_at', 'fulfilment_note']) + [
                    'table' => $order->visit_id ? DiningTable::whereKey(Visit::whereKey($order->visit_id)->value('dining_table_id'))->value('code') : null,
                ],
            'lines' => $order->lines->map(fn (OrderLine $l) => [
                'id' => $l->id,
                'line_type' => $l->line_type,
                'name' => $l->product->name,
                'pack_code' => $l->pack?->pack_code,
                'quantity' => (string) $l->quantity,
                'uom' => $l->uom_code,
                'unit_price' => (string) $l->unit_price,
                'gross_amount' => (string) $l->gross_amount,
                'discount_amount' => (string) $l->discount_amount,
                'net_amount' => (string) $l->net_amount,
                'note' => $l->note,
                'cooking' => $l->cooking_job_id && $jobs->has($l->cooking_job_id) ? $jobs[$l->cooking_job_id]->only(['status', 'portions', 'doneness']) : null,
            ])->values(),
            'payments' => $order->payments->map->only(['id', 'method', 'amount', 'tendered', 'change_amount', 'status'])->values(),
            'paid' => Decimal::money($paid),
            'remaining' => Decimal::money(Decimal::of($order->net_amount)->minus($paid)),
        ];
    }
}
