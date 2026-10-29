<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Integration\Xml\NmexmlImporter;
use App\Http\Controllers\Controller;
use App\Models\SourceDocument;
use App\Models\XmlImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Impor file NMEXML MSI/MSR dari sistem lama ke staging, dengan ringkasan selisih dan duplikat. */
class XmlImportController extends Controller
{
    public function index(): View
    {
        return view('core.xml.index', [
            'title' => 'Impor XML',
            'batches' => XmlImportBatch::latest()->paginate(30),
            'maxMb' => (int) (config('rasentra.xml.max_bytes') / 1024 / 1024),
        ]);
    }

    public function store(Request $request, NmexmlImporter $importer): RedirectResponse
    {
        $max = (int) (config('rasentra.xml.max_bytes') / 1024);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['MSI', 'MSR'])],
            'file' => ['required', 'file', "max:{$max}"],
        ]);
        $file = $data['file'];
        $batch = $importer->import($file->get(), $file->getClientOriginalName(), $data['kind']);
        Audit::record('xml.imported', $batch, $request->user()->id, after: ['file' => $batch->file_name, 'documents' => $batch->document_count, 'duplicates' => $batch->duplicate_count]);

        return redirect()->route('core.xml.show', $batch)->with('ok', "File diimpor: {$batch->document_count} dokumen baru, {$batch->duplicate_count} duplikat dilewati.");
    }

    public function show(XmlImportBatch $batch): View
    {
        return view('core.xml.show', [
            'title' => "Impor {$batch->kind} {$batch->file_name}",
            'batch' => $batch,
            'documents' => SourceDocument::where('xml_import_batch_id', $batch->id)->orderBy('source_no')->paginate(50),
        ]);
    }
}
