<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Crm\MemberImporter;
use App\Http\Controllers\Controller;
use App\Models\Crm\Import;
use App\Models\Crm\Tag;
use App\Models\Outlet;
use App\Support\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Rasentra CRM: impor daftar member (nama + nomor HP) per outlet dari Excel/CSV. */
class ImportController extends Controller
{
    public function index(): View
    {
        return view('core.crm.imports.index', [
            'title' => 'Impor member outlet',
            'imports' => Import::with(['outlet:id,code,name', 'user:id,name'])->latest()->paginate(30),
            'outlets' => Outlet::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'tags' => Tag::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, MemberImporter $importer): RedirectResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'exists:outlets,id'],
            'tag_id' => ['nullable', 'exists:crm_tags,id'],
            'file' => ['required', 'file', 'max:20480', 'mimes:xlsx,csv,txt'],
        ]);
        $file = $data['file'];
        try {
            $import = $importer->import($file->getRealPath(), $file->getClientOriginalName(), (int) $data['outlet_id'], $request->user()->id, $data['tag_id'] ?? null);
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }
        Audit::record('crm.members.imported', $import, $request->user()->id, after: $import->only(['file_name', 'created_count', 'merged_count', 'error_count']));

        return redirect()->route('core.crm.imports.show', $import)
            ->with('ok', "Impor selesai: {$import->created_count} pelanggan baru, {$import->merged_count} digabung dengan data yang ada, {$import->error_count} baris dilewati.");
    }

    public function show(Import $import): View
    {
        return view('core.crm.imports.show', ['title' => "Impor {$import->file_name}", 'import' => $import->load('outlet', 'user')]);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Kode Member', 'Nama', 'No HP', 'Email', 'Tanggal Lahir', 'Kota', 'Tanggal Daftar']);
            fputcsv($out, ['M-0001', 'Budi Santoso', '0812-3456-7890', 'budi@contoh.id', '17/08/1985', 'Surakarta', '01/02/2025']);
            fclose($out);
        }, 'templat-impor-member.csv', ['Content-Type' => 'text/csv']);
    }
}
