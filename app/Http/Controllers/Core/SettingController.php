<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Settings\Settings;
use App\Domain\Sync\Publications;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Outlet;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pengaturan umum. Isi di level grup untuk semua outlet; isi di level CV atau outlet hanya
 * bila memang perlu berbeda. Kolom kosong berarti mengikuti level di atasnya.
 */
class SettingController extends Controller
{
    public function index(Request $request): View
    {
        [$scope, $scopeId] = $this->scope($request);

        return view('core.ops.settings', [
            'title' => 'Pengaturan umum',
            'keys' => Settings::KEYS,
            'scope' => $scope,
            'scopeId' => $scopeId,
            'values' => Setting::where('scope', $scope)->where('scope_id', $scopeId)->pluck('value', 'key'),
            'global' => Setting::where('scope', 'global')->pluck('value', 'key'),
            'companies' => BusinessUnit::orderBy('code')->get(),
            'outlets' => Outlet::orderBy('code')->get(),
        ]);
    }

    public function update(Request $request, Publications $publications): RedirectResponse
    {
        [$scope, $scopeId] = $this->scope($request);
        $data = $request->validate(['values' => ['array']]);
        $changed = 0;
        foreach (Settings::KEYS as $key => [, , $type]) {
            $raw = $data['values'][$key] ?? null;
            if ($type === 'bool' && $scope === 'global') {
                $raw = isset($data['values'][$key]) ? '1' : '0';
            }
            $row = Setting::where('scope', $scope)->where('scope_id', $scopeId)->where('key', $key)->first();
            if ($raw === null || $raw === '') {
                if ($row && $scope !== 'global') {
                    // Kosong = ikut level atas. Nilai null dikirim agar outlet ikut menghapus.
                    $row->update(['value' => null]);
                    $publications->master('setting', $row->id);
                    $row->delete();
                    $changed++;
                }

                continue;
            }
            $value = app(Settings::class)->cast($key, $raw);
            if ($row && $row->value === $value) {
                continue;
            }
            $row = Setting::updateOrCreate(['scope' => $scope, 'scope_id' => $scopeId, 'key' => $key], ['value' => $value, 'updated_by' => $request->user()->id]);
            $publications->master('setting', $row->id);
            $changed++;
        }
        Audit::record('settings.updated', 'settings:'.$scope.($scopeId ? '-'.$scopeId : ''), $request->user()->id, after: ['changed' => $changed]);

        return back()->with('ok', "{$changed} pengaturan diperbarui dan dikirim ke outlet terkait.");
    }

    private function scope(Request $request): array
    {
        $request->validate(['scope' => ['nullable', Rule::in(['global', 'company', 'outlet'])], 'scope_id' => ['nullable', 'integer']]);
        $scope = $request->input('scope', 'global');

        return [$scope, $scope === 'global' ? null : (int) $request->input('scope_id')];
    }
}
