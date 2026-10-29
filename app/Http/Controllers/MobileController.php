<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\PriceResolver;
use App\Domain\CookingService\CookingService;
use App\Domain\Kitchen\KitchenBoard;
use App\Domain\Kitchen\KitchenService;
use App\Domain\Sales\Approval;
use App\Domain\Sales\OrderService;
use App\Domain\Sales\TableService;
use App\Models\CookingFeeRule;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\OrderLine;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\TicketItem;
use App\Models\User;
use App\Models\Visit;
use App\Support\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rasentra Mobile Terminal: tablet pelayan. Peta meja, buka kunjungan, catat menu dengan
 * modifier dan catatan, kirim dapur per gelombang, antar hidangan yang siap, minta masak
 * daging yang dibeli tamu di meatshop, lalu minta bayar. Pembayaran tetap di kasir.
 */
class MobileController extends Controller
{
    public function __construct(
        private readonly TableService $tables,
        private readonly OrderService $orders,
        private readonly KitchenService $kitchen,
    ) {}

    public function app(): View
    {
        return view('mobile.app');
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $outlet = Outlet::findOrFail($user->outlet_id);

        return response()->json([
            'user' => $user->only(['id', 'name', 'role']),
            'outlet' => $outlet->only(['id', 'code', 'name', 'has_restaurant', 'has_cooking_fee']),
            'categories' => Product::where('is_active', true)->where('type', 'menu')->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'modifiers' => config('rasentra.menu_modifiers'),
            'quick_notes' => config('rasentra.quick_notes'),
            'cooking_methods' => $outlet->has_cooking_fee ? CookingFeeRule::query()
                ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))
                ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->pluck('method')->unique()->values() : [],
            'approvers' => User::where('outlet_id', $outlet->id)->where('is_active', true)->whereIn('role', Approval::APPROVER_ROLES)
                ->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function tables(Request $request): JsonResponse
    {
        return response()->json(['tables' => $this->tables->map($request->user()->outlet_id), 'server_time' => now()->toIso8601String()]);
    }

    /** Menu restoran dengan harga dine-in yang berlaku di outlet ini. */
    public function menu(Request $request): JsonResponse
    {
        $outletId = $request->user()->outlet_id;
        $prices = app(PriceResolver::class);

        return response()->json(Product::where('is_active', true)->where('type', 'menu')
            ->when($request->query('q'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('category')->orderBy('name')->get()
            ->map(function (Product $p) use ($prices, $outletId) {
                try {
                    $price = $prices->resolve($p->id, $outletId, 'dine_in')['unit_price'];
                } catch (DomainException) {
                    return null;
                }

                return $p->only(['id', 'code', 'name', 'category', 'kitchen_station']) + ['price' => $price];
            })->filter()->values());
    }

    public function open(Request $request, DiningTable $table): JsonResponse
    {
        $data = $request->validate(['guests' => ['required', 'integer', 'min:1', 'max:50']]);
        $visit = $this->tables->open($request->user()->outlet_id, $table, $data['guests'], $request->user());

        return response()->json($this->tables->detail($visit), 201);
    }

    public function show(Request $request, string $token): JsonResponse
    {
        return response()->json($this->tables->detail($this->visit($request, $token)));
    }

    public function addItem(Request $request, string $token): JsonResponse
    {
        $visit = $this->visit($request, $token);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'modifiers' => ['nullable', 'array'],
            'modifiers.*' => ['string', 'max:40'],
            'note' => ['nullable', 'string', 'max:200'],
            'course' => ['nullable', 'in:starter,main,dessert'],
        ]);
        $product = Product::findOrFail($data['product_id']);
        if ($product->type !== 'menu') {
            throw new DomainException('not_menu', 'Hanya menu restoran yang bisa dipesan dari tablet.');
        }
        $this->orders->addSku($this->tables->order($visit), $product, (string) ($data['quantity'] ?? 1), array_filter([
            'modifiers' => $this->tables->modifiers($product, $data['modifiers'] ?? []) ?: null,
            'note' => $data['note'] ?? null,
            'course' => $data['course'] ?? null,
        ]));

        return response()->json($this->tables->detail($visit));
    }

    public function voidLine(Request $request, string $token, OrderLine $line): JsonResponse
    {
        $visit = $this->visit($request, $token);
        $order = $this->tables->order($visit);
        if ($line->order_id !== $order->id) {
            throw new DomainException('line_unknown', 'Baris bukan milik meja ini.', 404);
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200'], 'approval' => ['nullable', 'array']]);
        $this->orders->voidLine($line, $request->user(), $data['reason'] ?? null, $data['approval'] ?? null);

        return response()->json($this->tables->detail($visit));
    }

    public function send(Request $request, string $token): JsonResponse
    {
        $visit = $this->visit($request, $token);
        $data = $request->validate(['course' => ['nullable', 'in:starter,main,dessert']]);
        $tickets = $this->kitchen->send($this->tables->order($visit), $data['course'] ?? null);

        return response()->json(['tickets' => count($tickets)] + $this->tables->detail($visit));
    }

    /** Antar ke meja: item yang Siap ditandai Disajikan (peran expeditor untuk meja ini). */
    public function serve(Request $request, string $token): JsonResponse
    {
        $visit = $this->visit($request, $token);
        $order = $this->tables->order($visit);
        $data = $request->validate(['items' => ['nullable', 'array'], 'items.*' => ['string']]);
        $items = TicketItem::whereIn('kitchen_ticket_id', KitchenTicket::where('order_id', $order->id)->pluck('id'))
            ->where('status', 'ready')->when($data['items'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))->get();
        if ($items->isEmpty()) {
            throw DomainException::conflict('nothing_ready', 'Belum ada hidangan yang siap diantar.');
        }
        $board = app(KitchenBoard::class);
        $items->each(fn (TicketItem $i) => $board->bumpItem($i, $request->user(), true));

        return response()->json(['served' => $items->count()] + $this->tables->detail($visit));
    }

    /** Daging yang dibeli tamu di kasir meatshop dimasak di resto (cooking fee, hak layanan sekali pakai). */
    public function cookFromReceipt(Request $request, string $token): JsonResponse
    {
        $visit = $this->visit($request, $token);
        $data = $request->validate([
            'receipt_no' => ['required', 'string', 'max:40'],
            'pack_code' => ['required', 'string', 'max:64'],
            'portions' => ['nullable', 'integer', 'min:1', 'max:20'],
            'method' => ['nullable', 'string', 'max:40'],
            'doneness' => ['nullable', 'string', 'max:40'],
            'sauce' => ['nullable', 'string', 'max:60'],
            'allergy_note' => ['nullable', 'string', 'max:200'],
        ]);
        app(CookingService::class)->requestFromReceipt($this->tables->order($visit), trim($data['receipt_no']), trim($data['pack_code']), $request->user(), $data);

        return response()->json($this->tables->detail($visit));
    }

    public function bill(Request $request, string $token): JsonResponse
    {
        $visit = $this->tables->requestBill($this->visit($request, $token), $request->user());

        return response()->json($this->tables->detail($visit));
    }

    public function move(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['table_id' => ['required', 'integer', 'exists:dining_tables,id']]);
        $visit = $this->tables->move($this->visit($request, $token), DiningTable::findOrFail($data['table_id']), $request->user());

        return response()->json($this->tables->detail($visit));
    }

    public function guests(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['guests' => ['required', 'integer', 'min:1', 'max:50']]);

        return response()->json($this->tables->detail($this->tables->guests($this->visit($request, $token), $data['guests'])));
    }

    public function close(Request $request, string $token): JsonResponse
    {
        $this->tables->close($this->visit($request, $token), $request->user());

        return response()->json(['ok' => true]);
    }

    private function visit(Request $request, string $token): Visit
    {
        return $this->tables->visit($request->user()->outlet_id, $token);
    }
}
