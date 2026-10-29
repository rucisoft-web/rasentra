<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Recipe\RecipeService;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\RecipeVersion;
use App\Models\Uom;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Resep berversi untuk menu, bahan setengah jadi dan jasa masak. Perubahan dibuat sebagai
 * draft versi baru; saat disetujui, versi lama pensiun dan resep dikirim ke outlet.
 */
class RecipeController extends Controller
{
    public const KINDS = ['menu' => 'Menu', 'stocked_prep' => 'Prep berstok', 'virtual_prep' => 'Prep virtual', 'cooking_service' => 'Jasa masak'];

    public function index(Request $request): View
    {
        $recipes = Recipe::with(['product', 'versions' => fn ($q) => $q->orderByDesc('version')])
            ->when($request->query('q'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')->get()
            ->when($request->query('kind'), fn ($c, $k) => $c->filter(fn ($r) => $r->versions->first()?->kind === $k))
            ->when($request->query('status') === 'draft', fn ($c) => $c->filter(fn ($r) => $r->versions->contains('status', 'draft')));

        return view('core.recipes.index', ['title' => 'Resep', 'recipes' => $recipes, 'kinds' => self::KINDS]);
    }

    public function show(Request $request, Recipe $recipe): View
    {
        $versions = $recipe->versions()->orderByDesc('version')->get();
        $version = $request->query('v') ? $versions->firstWhere('id', (int) $request->query('v')) : null;
        $version ??= $versions->firstWhere('status', 'draft') ?? $versions->firstWhere('status', 'approved') ?? $versions->first();

        return view('core.recipes.show', [
            'title' => "Resep {$recipe->name}",
            'recipe' => $recipe->load('product'),
            'versions' => $versions,
            'version' => $version?->load('lines.component'),
            'kinds' => self::KINDS,
            'uoms' => Uom::orderBy('code')->pluck('code'),
            'approver' => $version?->approved_by ? User::whereKey($version->approved_by)->value('name') : null,
            'editor' => $version?->edited_by ? User::whereKey($version->edited_by)->value('name') : null,
            'mine' => $version?->status === 'draft' && $version->edited_by !== null && (int) $version->edited_by === (int) $request->user()->id,
            'usedBy' => RecipeLine::query()->join('recipe_versions as rv', 'rv.id', '=', 'recipe_lines.recipe_version_id')
                ->join('recipes', 'recipes.id', '=', 'rv.recipe_id')->where('rv.status', 'approved')
                ->where('recipe_lines.component_product_id', $recipe->product_id)->distinct()->pluck('recipes.name'),
        ]);
    }

    public function draft(Request $request, Recipe $recipe): RedirectResponse
    {
        if ($recipe->versions()->where('status', 'draft')->exists()) {
            throw DomainException::conflict('draft_exists', 'Resep ini sudah punya draft. Lanjutkan draft tersebut.');
        }
        $from = $recipe->versions()->where('status', 'approved')->orderByDesc('version')->first() ?? $recipe->versions()->orderByDesc('version')->first();

        $draft = DB::transaction(function () use ($recipe, $from, $request) {
            $draft = RecipeVersion::create([
                'recipe_id' => $recipe->id,
                'version' => (int) $recipe->versions()->max('version') + 1,
                'yield_quantity' => $from?->yield_quantity ?? 1,
                'yield_uom' => $from?->yield_uom ?? 'PORSI',
                'kind' => $from?->kind ?? 'menu',
                'status' => 'draft',
                'edited_by' => $request->user()->id,
                'outlet_ids' => $from?->outlet_ids,
            ]);
            foreach ($from?->lines ?? [] as $line) {
                RecipeLine::create(['recipe_version_id' => $draft->id] + $line->only(['component_product_id', 'quantity', 'uom_code', 'modifier_key']));
            }
            Audit::record('recipe.drafted', $draft, $request->user()->id, after: ['from_version' => $from?->version]);

            return $draft;
        });

        return redirect()->route('core.recipes.show', [$recipe, 'v' => $draft->id])->with('ok', "Draft versi {$draft->version} dibuat dari versi {$from?->version}.");
    }

    public function update(Request $request, RecipeVersion $version): RedirectResponse
    {
        if ($version->status !== 'draft') {
            throw DomainException::conflict('recipe_locked', 'Hanya draft yang dapat diubah.');
        }
        $data = $request->validate([
            'yield_quantity' => ['required', 'numeric', 'gt:0'],
            'yield_uom' => ['required', 'exists:uoms,code'],
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'code' => ['array'],
            'code.*' => ['nullable', 'string'],
            'qty' => ['array'],
            'qty.*' => ['nullable', 'numeric', 'gt:0'],
            'uom' => ['array'],
            'modifier' => ['array'],
        ]);

        $lines = [];
        foreach ($data['code'] ?? [] as $i => $code) {
            if (blank($code)) {
                continue;
            }
            $product = Product::where('code', trim($code))->first() ?? throw new DomainException('code_unknown', "Kode bahan {$code} tidak dikenal.");
            if ($product->id === $version->recipe->product_id) {
                throw new DomainException('recipe_self', 'Resep tidak boleh memakai produknya sendiri sebagai bahan.');
            }
            empty($data['qty'][$i]) && throw new DomainException('qty_required', "Isi jumlah untuk bahan {$code}.");
            $lines[] = [
                'component_product_id' => $product->id,
                'quantity' => Decimal::qty((string) $data['qty'][$i]),
                'uom_code' => $data['uom'][$i] ?? $product->uom_code,
                'modifier_key' => ($data['modifier'][$i] ?? null) ?: null,
            ];
        }
        if ($lines === []) {
            throw new DomainException('recipe_empty', 'Resep minimal berisi satu bahan.');
        }

        DB::transaction(function () use ($version, $data, $lines, $request) {
            $version->update(['yield_quantity' => Decimal::qty((string) $data['yield_quantity']), 'yield_uom' => $data['yield_uom'], 'kind' => $data['kind'], 'edited_by' => $request->user()->id]);
            $version->lines()->delete();
            foreach ($lines as $line) {
                RecipeLine::create(['recipe_version_id' => $version->id] + $line);
            }
            Audit::record('recipe.draft_updated', $version, $request->user()->id, after: ['lines' => count($lines)]);
        });

        return back()->with('ok', 'Draft resep disimpan.');
    }

    public function approve(Request $request, RecipeVersion $version, RecipeService $recipes): RedirectResponse
    {
        if ($version->status !== 'draft') {
            throw DomainException::conflict('recipe_locked', 'Versi ini bukan draft.');
        }
        $recipes->approve($version, $request->user());

        return back()->with('ok', "Versi {$version->version} disetujui dan dikirim ke outlet. Versi sebelumnya dipensiunkan.");
    }
}
