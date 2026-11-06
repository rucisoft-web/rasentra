<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Sync\Publications;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Outlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Table management dari pusat: area, kapasitas dan QR self ordering per meja untuk setiap
 * outlet resto. Perubahan dikirim ke Rasentra Lokal outlet itu.
 */
class TableController extends Controller
{
    public function index(Request $request): View
    {
        $outlets = Outlet::where('has_restaurant', true)->orderBy('code')->get();
        $outlet = $outlets->firstWhere('id', (int) $request->query('outlet')) ?? $outlets->first();

        return view('core.ops.tables', [
            'title' => 'Meja & QR self ordering',
            'outlets' => $outlets,
            'outlet' => $outlet,
            'tables' => $outlet ? DiningTable::where('outlet_id', $outlet->id)->orderBy('area')->orderBy('code')->get() : collect(),
        ]);
    }

    public function store(Request $request, Publications $publications): RedirectResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'code' => ['required', 'string', 'max:20', Rule::unique('dining_tables', 'code')->where('outlet_id', $request->input('outlet_id'))],
            'area' => ['nullable', 'string', 'max:40'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
        ]);
        $table = DiningTable::create($data + ['qr_token' => Str::random(32), 'is_active' => true]);
        $publications->master('dining_table', $table->id);
        Audit::record('table.created', $table, $request->user()->id, after: $table->only(['code', 'area', 'capacity']), outletId: $table->outlet_id);

        return back()->with('ok', "Meja {$table->code} ditambahkan.");
    }

    public function update(Request $request, DiningTable $table, Publications $publications): RedirectResponse
    {
        $data = $request->validate([
            'area' => ['nullable', 'string', 'max:40'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $table->update(['area' => $data['area'] ?? null, 'capacity' => $data['capacity'], 'is_active' => (bool) ($data['is_active'] ?? false)]);
        $publications->master('dining_table', $table->id);

        return back()->with('ok', "Meja {$table->code} diperbarui.");
    }

    /** QR baru membuat QR lama yang tercetak tidak berlaku lagi (mis. stiker hilang atau difoto). */
    public function regenerate(Request $request, DiningTable $table, Publications $publications): RedirectResponse
    {
        $table->update(['qr_token' => Str::random(32)]);
        $publications->master('dining_table', $table->id);
        Audit::record('table.qr_regenerated', $table, $request->user()->id, outletId: $table->outlet_id);

        return back()->with('ok', "QR meja {$table->code} diganti. Cetak ulang stikernya.");
    }

    public function print(Outlet $outlet): View
    {
        $base = rtrim((string) config('rasentra.self_order_url') ?: url('/'), '/');

        return view('core.ops.tables-print', [
            'outlet' => $outlet,
            'tables' => DiningTable::where('outlet_id', $outlet->id)->where('is_active', true)->whereNotNull('qr_token')->orderBy('area')->orderBy('code')->get()
                ->map(fn ($t) => ['table' => $t, 'url' => $base.'/order/'.$t->qr_token]),
        ]);
    }
}
