<?php

namespace App\Http\Controllers\Core;

use App\Domain\Promotion\PromotionDesigner;
use App\Domain\Promotion\PromotionPublisher;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionVersion;
use App\Models\Region;
use App\Models\User;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Promotion Designer: rancang, simulasikan, terbitkan dan hentikan promo dari kantor pusat. */
class PromotionController extends Controller
{
    public const STATUS = [
        'draft' => ['Draft', 'warn'],
        'simulated' => ['Sudah disimulasi', 'info'],
        'approved' => ['Disetujui', 'info'],
        'published' => ['Terbit', 'ok'],
        'superseded' => ['Digantikan', 'mute'],
        'expired' => ['Berakhir', 'mute'],
    ];

    public function __construct(private readonly PromotionDesigner $designer) {}

    public function index(Request $request): View
    {
        $versions = PromotionVersion::with('promotion')->withCount('targets')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), fn ($q, $s) => $q->whereHas('promotion', fn ($p) => $p->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->orderByRaw("CASE status WHEN 'simulated' THEN 0 WHEN 'draft' THEN 1 WHEN 'published' THEN 2 ELSE 3 END")
            ->orderBy('priority')->paginate(40)->withQueryString();

        return view('core.promotions.index', [
            'title' => 'Promotion Designer',
            'versions' => $versions,
            'types' => PromotionDesigner::TYPES,
            'statuses' => self::STATUS,
        ]);
    }

    public function create(): View
    {
        return $this->form(new PromotionVersion([
            'type' => 'percent', 'scope' => 'item', 'stacking' => 'exclusive', 'priority' => 50, 'funded_by' => 'internal',
            'rules' => [], 'starts_at' => now()->addDay()->startOfDay(), 'ends_at' => now()->addDays(31)->startOfDay(),
        ]), null);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'alpha_dash', 'max:40', 'unique:promotions,code']]);
        [$name, $attrs] = $this->fromRequest($request);
        $version = $this->designer->create($request->user(), strtoupper($request->input('code')), $name, $attrs);

        return redirect()->route('core.promotions.show', $version)->with('ok', 'Draft promo dibuat. Jalankan simulasi untuk memeriksa hasilnya.');
    }

    public function edit(PromotionVersion $version): View
    {
        return $this->form($version, $version->promotion);
    }

    public function update(Request $request, PromotionVersion $version): RedirectResponse
    {
        [$name, $attrs] = $this->fromRequest($request);
        $this->designer->update($version, $request->user(), $name, $attrs);

        return redirect()->route('core.promotions.show', $version)->with('ok', 'Promo diperbarui. Simulasikan ulang sebelum diterbitkan.');
    }

    public function show(PromotionVersion $version, PromotionPublisher $publisher): View
    {
        $version->load('promotion');
        $sim = session('simulation');
        if ($sim && (int) ($sim['version_id'] ?? 0) !== $version->id) {
            $sim = null;
        }

        return view('core.promotions.show', [
            'title' => "{$version->promotion->code} v{$version->version}",
            'version' => $version,
            'types' => PromotionDesigner::TYPES,
            'lineTypes' => PromotionDesigner::LINE_TYPES,
            'statuses' => self::STATUS,
            'ruleText' => $this->describe($version),
            'history' => PromotionVersion::where('promotion_id', $version->promotion_id)->orderByDesc('version')->get(),
            'receipts' => $version->status === 'published' || $version->status === 'expired' ? $publisher->receiptStatus($version) : [],
            'outlets' => Outlet::where('is_active', true)->orderBy('code')->get(),
            'regions' => Region::orderBy('name')->get(),
            'products' => Product::where('is_active', true)->whereIn('type', ['weighed', 'retail', 'menu', 'service'])->orderBy('name')->get(['id', 'code', 'name', 'uom_code']),
            'sim' => $sim,
            'names' => User::whereIn('id', array_filter([$version->created_by, $version->approved_by]))->pluck('name', 'id'),
            'productNames' => Product::pluck('name', 'id'),
            'promoNames' => DB::table('promotion_versions as v')->join('promotions as p', 'p.id', '=', 'v.promotion_id')->pluck('p.name', 'v.id'),
        ]);
    }

    public function simulate(Request $request, PromotionVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'channel' => ['required', Rule::in(array_keys(PriceController::CHANNELS))],
            'product' => ['array'],
            'product.*' => ['nullable', 'exists:products,id'],
            'qty' => ['array'],
            'qty.*' => ['nullable', 'numeric', 'gt:0'],
        ]);
        $basket = [];
        foreach ($data['product'] ?? [] as $i => $productId) {
            if ($productId && ! empty($data['qty'][$i])) {
                $basket[] = ['product_id' => (int) $productId, 'quantity' => (string) $data['qty'][$i]];
            }
        }
        $result = $this->designer->simulate($version, $request->user(), (int) $data['outlet_id'], $data['channel'], $basket);

        return redirect()->route('core.promotions.show', $version)
            ->with('simulation', ['version_id' => $version->id, 'input' => $data] + $result)
            ->with('ok', 'Simulasi selesai. Promo ini memberi potongan '.Fmt::money($result['this_discount']).' pada keranjang contoh.');
    }

    public function publish(Request $request, PromotionVersion $version): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', Rule::in(['all', 'regions', 'outlets'])],
            'regions' => ['array'],
            'outlets' => ['array'],
        ]);
        $target = match ($data['target']) {
            'all' => ['all' => true],
            'regions' => ['regions' => $data['regions'] ?? []],
            'outlets' => ['outlets' => $data['outlets'] ?? []],
        };
        $published = $this->designer->publish($version, $request->user(), $target);

        return back()->with('ok', "Promo terbit ke {$published->targets->count()} outlet. Status penerimaan tiap outlet tampil di bawah.");
    }

    public function newVersion(Request $request, PromotionVersion $version): RedirectResponse
    {
        $new = $this->designer->newVersion($version, $request->user());

        return redirect()->route('core.promotions.edit', $new)->with('ok', "Versi {$new->version} dibuat dari versi {$version->version}.");
    }

    public function stop(Request $request, PromotionVersion $version): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->designer->stop($version, $request->user(), $data['reason']);

        return back()->with('ok', 'Promo dihentikan dan perubahan dikirim ke outlet.');
    }

    private function form(PromotionVersion $version, ?Promotion $promotion): View
    {
        $rules = $version->rules ?? [];
        $codes = fn (array $ids) => Product::whereIn('id', $ids)->pluck('code')->implode(', ');

        return view('core.promotions.form', [
            'title' => $promotion ? "Ubah {$promotion->code} v{$version->version}" : 'Promo baru',
            'version' => $version,
            'promotion' => $promotion,
            'rules' => $rules,
            'productCodes' => $codes($rules['product_ids'] ?? []),
            'buyCode' => isset($rules['buy_product_id']) ? Product::whereKey($rules['buy_product_id'])->value('code') : '',
            'getCode' => isset($rules['get_product_id']) ? Product::whereKey($rules['get_product_id'])->value('code') : '',
            'types' => PromotionDesigner::TYPES,
            'lineTypes' => PromotionDesigner::LINE_TYPES,
            'channels' => PriceController::CHANNELS,
            'categories' => Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    /** @return array{0:string, 1:array} nama promo dan atribut versi dari formulir */
    private function fromRequest(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(array_keys(PromotionDesigner::TYPES))],
            'scope' => ['required', Rule::in(['item', 'basket'])],
            'stacking' => ['required', Rule::in(['exclusive', 'stackable', 'best_benefit'])],
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
            'funded_by' => ['required', Rule::in(['internal', 'supplier', 'bank', 'platform'])],
            'channels' => ['array'],
            'channels.*' => [Rule::in(array_keys(PriceController::CHANNELS))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'categories' => ['array'],
            'product_codes' => ['nullable', 'string', 'max:2000'],
            'line_types' => ['array'],
            'line_types.*' => [Rule::in(array_keys(PromotionDesigner::LINE_TYPES))],
            'percent' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'gt:0'],
            'min_spend' => ['nullable', 'numeric', 'gt:0'],
            'tier_weight' => ['array'],
            'tier_weight.*' => ['nullable', 'numeric', 'gt:0'],
            'tier_percent' => ['array'],
            'tier_percent.*' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'buy_code' => ['nullable', 'string'],
            'buy_qty' => ['nullable', 'integer', 'min:1'],
            'get_code' => ['nullable', 'string'],
            'get_qty' => ['nullable', 'integer', 'min:1'],
            'max_multiple' => ['nullable', 'integer', 'min:1'],
        ]);

        $type = $data['type'];
        $scope = $type === 'amount' ? 'basket' : ($type === 'percent' ? $data['scope'] : 'item');
        $rules = [];
        $fail = fn (string $field, string $msg) => throw ValidationException::withMessages([$field => $msg]);

        if (! empty($data['categories'])) {
            $rules['categories'] = array_values($data['categories']);
        }
        if (! empty($data['product_codes'])) {
            $codes = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $data['product_codes']))));
            $ids = Product::whereIn('code', $codes)->pluck('id', 'code');
            $missing = array_diff($codes, $ids->keys()->all());
            if ($missing) {
                $fail('product_codes', 'Kode produk tidak dikenal: '.implode(', ', $missing));
            }
            $rules['product_ids'] = $ids->values()->map(fn ($id) => (int) $id)->all();
        }
        if (! empty($data['line_types'])) {
            $rules['line_types'] = array_values($data['line_types']);
        }

        switch ($type) {
            case 'percent':
                $data['percent'] ?? $fail('percent', 'Isi besar diskon (%).');
                $rules['percent'] = (string) $data['percent'];
                break;
            case 'amount':
                $data['amount'] ?? $fail('amount', 'Isi nominal potongan.');
                $rules['amount'] = (string) $data['amount'];
                break;
            case 'special_price':
                isset($data['price']) || $fail('price', 'Isi harga spesial.');
                empty($rules['product_ids']) && $fail('product_codes', 'Harga spesial wajib menyebut kode produk.');
                $rules['price'] = (string) $data['price'];
                break;
            case 'weight_tier':
                $tiers = [];
                foreach ($data['tier_weight'] ?? [] as $i => $w) {
                    if ($w && ! empty($data['tier_percent'][$i])) {
                        $tiers[] = ['min_weight' => number_format((float) $w, 3, '.', ''), 'percent' => (string) $data['tier_percent'][$i]];
                    }
                }
                $tiers === [] && $fail('tier_weight', 'Isi minimal satu tingkat berat dan diskonnya.');
                usort($tiers, fn ($a, $b) => (float) $a['min_weight'] <=> (float) $b['min_weight']);
                $rules['tiers'] = $tiers;
                break;
            case 'buy_x_get_y':
                $buy = Product::where('code', trim((string) ($data['buy_code'] ?? '')))->value('id') ?? $fail('buy_code', 'Kode produk beli tidak dikenal.');
                $get = Product::where('code', trim((string) ($data['get_code'] ?? '')))->value('id') ?? $fail('get_code', 'Kode produk gratis tidak dikenal.');
                $rules += ['buy_product_id' => (int) $buy, 'buy_qty' => (int) ($data['buy_qty'] ?? 1), 'get_product_id' => (int) $get, 'get_qty' => (int) ($data['get_qty'] ?? 1)];
                $rules['product_ids'] = array_values(array_unique([...($rules['product_ids'] ?? []), (int) $buy, (int) $get]));
                if (! empty($data['max_multiple'])) {
                    $rules['max_multiple'] = (int) $data['max_multiple'];
                }
                break;
        }
        if (! empty($data['max_discount']) && in_array($type, ['percent', 'amount', 'weight_tier'], true)) {
            $rules['max_discount'] = (string) $data['max_discount'];
        }
        if (! empty($data['min_spend']) && $scope === 'basket') {
            $rules['min_spend'] = (string) $data['min_spend'];
        }

        $tz = config('rasentra.display_timezone');

        return [$data['name'], [
            'type' => $type,
            'scope' => $scope,
            'rules' => $rules,
            'channels' => empty($data['channels']) ? null : array_values($data['channels']),
            'priority' => (int) $data['priority'],
            'stacking' => $data['stacking'],
            'funded_by' => $data['funded_by'],
            'starts_at' => Carbon::parse($data['starts_at'], $tz)->utc(),
            'ends_at' => empty($data['ends_at']) ? null : Carbon::parse($data['ends_at'], $tz)->utc(),
        ]];
    }

    /** Uraian aturan dalam bahasa sehari-hari untuk layar persetujuan. */
    private function describe(PromotionVersion $v): array
    {
        $r = $v->rules ?? [];
        $money = fn ($x) => Fmt::money($x);
        $names = fn (array $ids) => Product::whereIn('id', $ids)->get(['code', 'name'])->map(fn ($p) => "{$p->name} ({$p->code})")->implode(', ');
        $out = [];
        $out[] = match ($v->type) {
            'percent' => "Diskon {$r['percent']}%".($v->scope === 'basket' ? ' dari total keranjang yang memenuhi syarat' : ' per baris yang memenuhi syarat'),
            'amount' => 'Potongan '.$money($r['amount'] ?? 0).' dari total keranjang',
            'special_price' => 'Harga spesial '.$money($r['price'] ?? 0).' per satuan',
            'weight_tier' => 'Diskon bertingkat menurut total berat: '.collect($r['tiers'] ?? [])->map(fn ($t) => '≥ '.rtrim(rtrim($t['min_weight'], '0'), '.')." kg {$t['percent']}%")->implode(', '),
            'buy_x_get_y' => 'Beli '.($r['buy_qty'] ?? 1).' '.$names([$r['buy_product_id'] ?? 0]).' gratis '.($r['get_qty'] ?? 1).' '.$names([$r['get_product_id'] ?? 0]).(isset($r['max_multiple']) ? " (maks. {$r['max_multiple']} kali)" : ''),
            default => $v->type,
        };
        if (! empty($r['categories'])) {
            $out[] = 'Kategori: '.implode(', ', $r['categories']);
        }
        if (! empty($r['product_ids']) && $v->type !== 'buy_x_get_y') {
            $out[] = 'Produk: '.$names($r['product_ids']);
        }
        if (! empty($r['line_types'])) {
            $out[] = 'Jenis baris: '.implode(', ', array_map(fn ($t) => PromotionDesigner::LINE_TYPES[$t] ?? $t, $r['line_types']));
        }
        if (isset($r['min_spend'])) {
            $out[] = 'Minimal belanja '.$money($r['min_spend']);
        }
        if (isset($r['max_discount'])) {
            $out[] = 'Potongan maksimal '.$money($r['max_discount']);
        }
        $out[] = 'Kanal: '.(empty($v->channels) ? 'semua kanal' : implode(', ', array_map(fn ($c) => PriceController::CHANNELS[$c] ?? $c, $v->channels)));

        return $out;
    }
}
