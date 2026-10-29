<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\PriceItem;
use App\Models\Product;
use App\Models\Uom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Master produk: kode selalu string (nol di depan dipertahankan), tipe, satuan, kategori, station dapur. */
class ProductController extends Controller
{
    public const TYPES = [
        'weighed' => 'Timbang (Pack ID)',
        'retail' => 'Retail (barcode)',
        'menu' => 'Menu resto',
        'service' => 'Jasa (cooking fee)',
        'ingredient' => 'Bahan baku',
        'prep' => 'Setengah jadi (prep)',
    ];

    public function index(Request $request): View
    {
        $products = Product::query()
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "{$s}%")->orWhere('barcode', $s)))
            ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->orderBy('code')->paginate(60)->withQueryString();

        $published = PriceItem::query()->join('price_versions as v', 'v.id', '=', 'price_items.price_version_id')
            ->where('v.status', 'published')->whereIn('price_items.product_id', $products->pluck('id'))
            ->groupBy('price_items.product_id')->selectRaw('price_items.product_id, COUNT(*) as n, MIN(price_items.unit_price) as lo, MAX(price_items.unit_price) as hi')
            ->get()->keyBy('product_id');

        return view('core.products.index', [
            'title' => 'Produk',
            'products' => $products,
            'published' => $published,
            'types' => self::TYPES,
            'categories' => Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Product(['is_active' => true, 'is_stocked' => true, 'type' => 'retail', 'uom_code' => 'PCS']));
    }

    public function edit(Product $product): View
    {
        return $this->form($product);
    }

    public function store(Request $request): RedirectResponse
    {
        $product = Product::create($this->validated($request, null));
        Audit::record('product.created', $product, $request->user()->id, after: $product->only(['code', 'name', 'type']));

        return redirect()->route('core.products.edit', $product)->with('ok', "Produk {$product->code} dibuat. Tambahkan harganya di Versi harga.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);
        $before = $product->only(array_keys($data));
        $product->update($data);
        Audit::record('product.updated', $product, $request->user()->id, before: $before, after: $product->only(array_keys($data)));

        return back()->with('ok', 'Produk disimpan.');
    }

    private function form(Product $product): View
    {
        return view('core.products.form', [
            'title' => $product->exists ? "{$product->code} · {$product->name}" : 'Produk baru',
            'product' => $product,
            'types' => self::TYPES,
            'uoms' => Uom::orderBy('code')->pluck('name', 'code'),
            'stations' => config('rasentra.kitchen.stations'),
            'categories' => Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'prices' => $product->exists ? PriceItem::query()->join('price_versions as v', 'v.id', '=', 'price_items.price_version_id')
                ->leftJoin('outlets', 'outlets.id', '=', 'v.outlet_id')
                ->where('price_items.product_id', $product->id)->orderByDesc('v.effective_from')
                ->get(['v.id as version_id', 'v.code', 'v.status', 'v.channel', 'v.effective_from', 'outlets.code as outlet_code', 'price_items.unit_price']) : collect(),
        ]);
    }

    private function validated(Request $request, ?Product $product): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[0-9A-Za-z\-_.]+$/', Rule::unique('products')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:64', Rule::unique('products')->ignore($product?->id)],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'uom_code' => ['required', 'exists:uoms,code'],
            'category' => ['nullable', 'string', 'max:40'],
            'kitchen_station' => ['nullable', Rule::in(array_keys(config('rasentra.kitchen.stations')))],
        ]);
        $data['is_weighed'] = $request->boolean('is_weighed') || $data['type'] === 'weighed';
        $data['is_stocked'] = $request->boolean('is_stocked');
        $data['is_active'] = $request->boolean('is_active');
        if ($data['type'] === 'menu' && empty($data['kitchen_station'])) {
            $data['kitchen_station'] = 'hot';
        }

        return $data;
    }
}
