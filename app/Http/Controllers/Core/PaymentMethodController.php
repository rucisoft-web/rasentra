<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Sync\Publications;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\PublicationVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Metode pembayaran grup: satu daftar dari pusat, bisa dibatasi per outlet, dipetakan ke akun SAP. */
class PaymentMethodController extends Controller
{
    public const TYPES = [
        'cash' => 'Tunai (laci kas)',
        'integrated' => 'Terintegrasi (QRIS/EDC menunggu konfirmasi provider)',
        'manual' => 'Manual dengan bukti (EDC manual, transfer)',
        'voucher' => 'Voucher',
        'points' => 'Poin member',
    ];

    public function index(): View
    {
        return view('core.ops.payment-methods', [
            'title' => 'Metode pembayaran',
            'methods' => PaymentMethod::orderBy('sort')->orderBy('name')->get(),
            'outlets' => Outlet::orderBy('code')->get(),
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request, Publications $publications): RedirectResponse
    {
        $data = $this->validated($request, null);
        $method = PaymentMethod::create($data);
        $publications->master('payment_method', $method->id);
        Audit::record('payment_method.created', $method, $request->user()->id, after: $method->only(['code', 'name', 'type']));

        return back()->with('ok', "Metode {$method->name} dibuat dan dikirim ke outlet.");
    }

    public function update(Request $request, PaymentMethod $method, Publications $publications): RedirectResponse
    {
        $before = $method->only(['name', 'type', 'is_active', 'outlet_ids', 'sap_account']);
        $method->update($this->validated($request, $method));
        // Outlet yang dicabut juga harus menerima perubahan: kirim ke semua.
        $publications->master('payment_method', $method->id);
        PublicationVersion::where('entity_type', 'payment_method')->where('entity_key', (string) $method->id)->latest('id')->first()?->update(['outlet_ids' => null]);
        Audit::record('payment_method.updated', $method, $request->user()->id, before: $before, after: $method->only(array_keys($before)));

        return back()->with('ok', "Metode {$method->name} diperbarui.");
    }

    private function validated(Request $request, ?PaymentMethod $method): array
    {
        $data = $request->validate([
            'code' => [$method ? 'prohibited' : 'required', 'alpha_dash', 'max:20', Rule::unique('payment_methods', 'code')],
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'requires_reference' => ['nullable', 'boolean'],
            'sap_account' => ['nullable', 'string', 'max:30'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
            'outlet_ids' => ['nullable', 'array'],
            'outlet_ids.*' => ['integer', 'exists:outlets,id'],
        ]);

        return array_filter(['code' => isset($data['code']) ? strtolower($data['code']) : null], fn ($v) => $v !== null) + [
            'name' => $data['name'],
            'type' => $data['type'],
            'requires_reference' => (bool) ($data['requires_reference'] ?? false),
            'sap_account' => $data['sap_account'] ?? null,
            'sort' => $data['sort'] ?? 0,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'outlet_ids' => empty($data['outlet_ids']) ? null : array_map('intval', $data['outlet_ids']),
        ];
    }
}
