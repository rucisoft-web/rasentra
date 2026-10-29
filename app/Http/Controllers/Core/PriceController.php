<?php

namespace App\Http\Controllers\Core;

use App\Domain\Catalog\PricePublisher;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\PriceItem;
use App\Models\PriceVersion;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Versi harga per outlet/kanal: draft, isi harga, terbitkan oleh rekan lain. */
class PriceController extends Controller
{
    public const CHANNELS = ['retail' => 'Retail', 'dine_in' => 'Dine-in', 'takeaway' => 'Takeaway', 'pickup' => 'Pickup', 'delivery' => 'Delivery'];

    public function __construct(private readonly PricePublisher $publisher) {}

    public function index(Request $request): View
    {
        $versions = PriceVersion::with('outlet')->withCount('items')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('outlet'), fn ($q, $o) => $o === 'all' ? $q->whereNull('outlet_id') : $q->where('outlet_id', $o))
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")->orderByDesc('effective_from')->paginate(40)->withQueryString();

        return view('core.prices.index', [
            'title' => 'Versi harga',
            'versions' => $versions,
            'outlets' => Outlet::orderBy('code')->get(),
            'channels' => self::CHANNELS,
            'sources' => PriceVersion::where('status', 'published')->orderByDesc('effective_from')->limit(50)->get(['id', 'code']),
            'names' => User::whereIn('id', $versions->pluck('created_by')->merge($versions->pluck('published_by'))->filter())->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'alpha_dash', 'unique:price_versions,code'],
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'channel' => ['nullable', Rule::in(array_keys(self::CHANNELS))],
            'effective_from' => ['required', 'date'],
            'copy_from' => ['nullable', 'exists:price_versions,id'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $version = $this->publisher->draft(
            $request->user(), strtoupper($data['code']), $data['outlet_id'] ?? null, $data['channel'] ?? null,
            Carbon::parse($data['effective_from'], config('rasentra.display_timezone'))->utc(),
            isset($data['copy_from']) ? PriceVersion::with('items')->find($data['copy_from']) : null,
            $data['note'] ?? null,
        );

        return redirect()->route('core.prices.show', $version)->with('ok', 'Draft versi harga dibuat. Ubah harga yang perlu, lalu minta rekan pusat lain untuk menerbitkan.');
    }

    public function show(PriceVersion $version): View
    {
        $items = PriceItem::with('product')->where('price_version_id', $version->id)->get()->sortBy(fn ($i) => $i->product->code)->values();

        return view('core.prices.show', [
            'title' => "Versi harga {$version->code}",
            'version' => $version->load('outlet'),
            'items' => $items,
            'current' => $this->currentPrices($version, $items->pluck('product_id')->all()),
            'channels' => self::CHANNELS,
            'names' => User::whereIn('id', array_filter([$version->created_by, $version->published_by]))->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request, PriceVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'prices' => ['array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
            'new_code' => ['array'],
            'new_code.*' => ['nullable', 'string'],
            'new_price' => ['array'],
            'new_price.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $prices = $data['prices'] ?? [];
        $unknown = [];
        foreach ($data['new_code'] ?? [] as $i => $code) {
            if (blank($code)) {
                continue;
            }
            $product = Product::where('code', trim($code))->orWhere('barcode', trim($code))->first();
            if ($product === null) {
                $unknown[] = $code;

                continue;
            }
            $prices[$product->id] = $data['new_price'][$i] ?? null;
        }
        $changed = $this->publisher->setItems($version, $request->user(), $prices);

        $msg = "{$changed} harga diperbarui.";
        if ($unknown) {
            return back()->with('ok', $msg)->with('error', 'Kode tidak dikenal: '.implode(', ', $unknown));
        }

        return back()->with('ok', $msg);
    }

    public function publish(Request $request, PriceVersion $version): RedirectResponse
    {
        $this->publisher->publish($version, $request->user());

        return back()->with('ok', "Versi {$version->code} terbit dan dikirim ke Rasentra Lokal ".($version->outlet_id ? 'outlet terkait.' : 'semua outlet.'));
    }

    /** Harga yang berlaku sekarang pada cakupan yang sama (outlet/kanal), untuk perbandingan. */
    private function currentPrices(PriceVersion $version, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('price_items')->join('price_versions as v', 'v.id', '=', 'price_items.price_version_id')
            ->whereIn('price_items.product_id', $productIds)
            ->where('v.status', 'published')->where('v.id', '!=', $version->id)
            ->where('v.effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('v.effective_to')->orWhere('v.effective_to', '>', now()))
            ->where(fn ($q) => $version->outlet_id ? $q->whereNull('v.outlet_id')->orWhere('v.outlet_id', $version->outlet_id) : $q->whereNull('v.outlet_id'))
            ->where(fn ($q) => $version->channel ? $q->whereNull('v.channel')->orWhere('v.channel', $version->channel) : $q->whereNull('v.channel'))
            ->orderByRaw('CASE WHEN v.outlet_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN v.channel IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('v.effective_from')
            ->get(['price_items.product_id', 'price_items.unit_price'])
            ->unique('product_id')->pluck('unit_price', 'product_id')->all();
    }
}
