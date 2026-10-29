<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\CookingFeeRule;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PublicationVersion;
use App\Models\RecipeVersion;
use App\Support\DomainException;
use App\Support\Fmt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Aturan cooking fee per outlet dan metode masak. Tarif tidak diubah di tempat: perubahan
 * dibuat sebagai versi baru dengan tanggal efektif, dan versi lama ditutup pada tanggal itu.
 */
class CookingFeeController extends Controller
{
    public const BASES = ['per_kg' => 'Per kg berat mentah', 'tiered' => 'Bertingkat per kg', 'per_portion' => 'Per porsi', 'per_pack' => 'Per pack'];

    public function index(): View
    {
        $rules = CookingFeeRule::orderBy('code')->orderByDesc('version')->get();

        return view('core.cooking-fees.index', [
            'title' => 'Cooking fee',
            'rules' => $rules,
            'outlets' => Outlet::orderBy('code')->get()->keyBy('id'),
            'services' => Product::where('type', 'service')->orderBy('name')->get()->keyBy('id'),
            'bases' => self::BASES,
            'recipes' => $this->serviceRecipes(),
        ]);
    }

    public function edit(CookingFeeRule $rule): View
    {
        return view('core.cooking-fees.edit', [
            'title' => "{$rule->code} v{$rule->version}",
            'rule' => $rule,
            'history' => CookingFeeRule::where('code', $rule->code)->orderByDesc('version')->get(),
            'outlets' => Outlet::orderBy('code')->get()->keyBy('id'),
            'services' => Product::where('type', 'service')->orderBy('name')->get()->keyBy('id'),
            'bases' => self::BASES,
            'recipes' => $this->serviceRecipes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'alpha_dash', 'max:40', Rule::unique('cooking_fee_rules', 'code')]]);
        $rule = DB::transaction(function () use ($request) {
            $rule = CookingFeeRule::create(['code' => strtoupper($request->input('code')), 'version' => 1, 'created_by' => $request->user()->id] + $this->attrs($request));
            $this->publish($rule);
            Audit::record('cooking_fee.created', $rule, $request->user()->id, after: $rule->only(['code', 'method', 'basis', 'rate']), outletId: $rule->outlet_id);

            return $rule;
        });

        return redirect()->route('core.cooking-fees.edit', $rule)->with('ok', "Tarif {$rule->code} dibuat dan dikirim ke outlet.");
    }

    public function supersede(Request $request, CookingFeeRule $rule): RedirectResponse
    {
        $latest = CookingFeeRule::where('code', $rule->code)->orderByDesc('version')->first();
        if ($latest->id !== $rule->id) {
            throw DomainException::conflict('not_latest', 'Ubah dari versi terbaru tarif ini.');
        }
        $attrs = $this->attrs($request);
        if ($attrs['effective_from']->lessThanOrEqualTo($rule->effective_from)) {
            throw new DomainException('effective_order', 'Tanggal berlaku versi baru harus setelah versi sebelumnya.');
        }

        $new = DB::transaction(function () use ($rule, $attrs, $request) {
            $rule->update(['effective_to' => $attrs['effective_from']]);
            $new = CookingFeeRule::create(['code' => $rule->code, 'version' => $rule->version + 1, 'created_by' => $request->user()->id] + $attrs);
            $this->publish($rule->fresh());
            $this->publish($new);
            Audit::record('cooking_fee.superseded', $new, $request->user()->id,
                before: $rule->only(['basis', 'rate', 'minimum_fee', 'tiers']), after: $new->only(['basis', 'rate', 'minimum_fee', 'tiers']), outletId: $new->outlet_id);

            return $new;
        });

        return redirect()->route('core.cooking-fees.edit', $new)->with('ok', "Versi {$new->version} berlaku mulai ".Fmt::dt($new->effective_from).'.');
    }

    private function attrs(Request $request): array
    {
        $data = $request->validate([
            'outlet_id' => ['nullable', 'exists:outlets,id'],
            'method' => ['nullable', 'alpha_dash', 'max:30'],
            'basis' => ['required', Rule::in(array_keys(self::BASES))],
            'rate' => ['required', 'numeric', 'min:0'],
            'minimum_fee' => ['nullable', 'numeric', 'min:0'],
            'tier_up_to' => ['array'],
            'tier_up_to.*' => ['nullable', 'numeric', 'gt:0'],
            'tier_rate' => ['array'],
            'tier_rate.*' => ['nullable', 'numeric', 'min:0'],
            'service_product_id' => ['required', Rule::exists('products', 'id')->where('type', 'service')],
            'recipe_version_id' => ['nullable', 'exists:recipe_versions,id'],
            'effective_from' => ['required', 'date'],
        ]);
        $tiers = [];
        foreach ($data['tier_up_to'] ?? [] as $i => $upTo) {
            if ($upTo && isset($data['tier_rate'][$i]) && $data['tier_rate'][$i] !== '') {
                $tiers[] = ['up_to' => (string) $upTo, 'rate' => (string) $data['tier_rate'][$i]];
            }
        }
        usort($tiers, fn ($a, $b) => (float) $a['up_to'] <=> (float) $b['up_to']);
        if ($data['basis'] === 'tiered' && $tiers === []) {
            throw new DomainException('tiers_required', 'Tarif bertingkat wajib berisi minimal satu tingkat berat.');
        }

        return [
            'outlet_id' => $data['outlet_id'] ?? null,
            'method' => ($data['method'] ?? null) ?: null,
            'basis' => $data['basis'],
            'rate' => $data['rate'],
            'minimum_fee' => $data['minimum_fee'] ?? 0,
            'tiers' => $data['basis'] === 'tiered' ? $tiers : null,
            'service_product_id' => $data['service_product_id'],
            'recipe_version_id' => $data['recipe_version_id'] ?? null,
            'effective_from' => Carbon::parse($data['effective_from'], config('rasentra.display_timezone'))->utc(),
        ];
    }

    private function publish(CookingFeeRule $rule): void
    {
        PublicationVersion::create([
            'entity_type' => 'cooking_fee_rule',
            'entity_key' => (string) $rule->id,
            'payload' => $rule->toArray(),
            'outlet_ids' => $rule->outlet_id ? [$rule->outlet_id] : null,
        ]);
    }

    private function serviceRecipes()
    {
        return RecipeVersion::with('recipe')->where('kind', 'cooking_service')->where('status', 'approved')->get()
            ->mapWithKeys(fn ($v) => [$v->id => "{$v->recipe->name} v{$v->version}"]);
    }
}
