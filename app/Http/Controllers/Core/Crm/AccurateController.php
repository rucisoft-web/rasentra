<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Integration\Accurate\AccurateClient;
use App\Domain\Integration\Accurate\AccurateSync;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Crm\AccurateConnection;
use App\Models\Crm\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Rasentra CRM: koneksi Accurate Online per perusahaan dan sinkron manual. */
class AccurateController extends Controller
{
    public function index(): View
    {
        Branch::syncFromOutlets();
        $companies = BusinessUnit::orderBy('code')->get();
        $connections = AccurateConnection::whereIn('business_unit_id', $companies->pluck('id'))->get()->keyBy('business_unit_id');

        return view('core.crm.accurate', [
            'title' => 'Integrasi Accurate Online',
            'companies' => $companies,
            'connections' => $connections,
            'outlets' => Branch::orderBy('code')->get(['id', 'code', 'name', 'business_unit_id']),
        ]);
    }

    public function update(Request $request, BusinessUnit $company): RedirectResponse
    {
        $data = $request->validate([
            'api_token' => ['nullable', 'string', 'max:500'],
            'signature_secret' => ['nullable', 'string', 'max:500'],
            'default_branch_id' => ['nullable', 'exists:crm_branches,id'],
            'sync_customers' => ['boolean'],
            'sync_invoices' => ['boolean'],
            'clear' => ['boolean'],
        ]);
        $conn = AccurateConnection::firstOrNew(['business_unit_id' => $company->id]);
        $conn->default_branch_id = $data['default_branch_id'] ?? null;
        $conn->sync_customers = (bool) ($data['sync_customers'] ?? false);
        $conn->sync_invoices = (bool) ($data['sync_invoices'] ?? false);
        // Token tidak pernah ditampilkan ulang: kosong berarti tetap memakai yang tersimpan.
        foreach (['api_token', 'signature_secret'] as $key) {
            if (filled($data[$key] ?? null)) {
                $conn->{$key} = trim($data[$key]);
            }
        }
        if ($data['clear'] ?? false) {
            $conn->api_token = $conn->signature_secret = null;
        }
        $conn->save();
        Audit::record('crm.accurate.updated', $conn, $request->user()->id, after: ['company' => $company->code, 'configured' => AccurateSync::configured($conn)]);

        return back()->with('ok', "Pengaturan Accurate {$company->code} disimpan.");
    }

    public function test(BusinessUnit $company, AccurateClient $client): RedirectResponse
    {
        $conn = AccurateConnection::where('business_unit_id', $company->id)->first();
        if (! $conn || ! AccurateSync::configured($conn)) {
            return back()->with('error', 'Isi API Token dan Signature Secret terlebih dahulu.');
        }
        try {
            return back()->with('ok', "Terhubung ke database Accurate \"{$client->ping($conn)}\".");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function sync(Request $request, BusinessUnit $company, AccurateSync $sync): RedirectResponse
    {
        $conn = AccurateConnection::where('business_unit_id', $company->id)->first();
        if (! $conn || ! AccurateSync::configured($conn)) {
            return back()->with('error', 'Isi API Token dan Signature Secret terlebih dahulu.');
        }
        try {
            $s = $sync->sync($conn);
        } catch (\Throwable $e) {
            return back()->with('error', 'Sinkron gagal: '.$e->getMessage());
        }
        Audit::record('crm.accurate.synced', $conn, $request->user()->id, after: $s);

        return back()->with('ok', "Sinkron {$company->code} selesai: {$conn->last_message}.");
    }
}
