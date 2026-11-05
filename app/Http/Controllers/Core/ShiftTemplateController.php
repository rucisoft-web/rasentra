<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Sync\Publications;
use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\PublicationVersion;
use App\Models\ShiftTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Template shift kerja staf yang dipakai semua outlet untuk menyusun roster. */
class ShiftTemplateController extends Controller
{
    public function index(): View
    {
        return view('core.ops.shift-templates', [
            'title' => 'Template shift staf',
            'templates' => ShiftTemplate::orderBy('starts_at')->get(),
            'outlets' => Outlet::orderBy('code')->get(),
        ]);
    }

    public function store(Request $request, Publications $publications): RedirectResponse
    {
        $request->validate(['code' => ['required', 'alpha_dash', 'max:20', Rule::unique('shift_templates', 'code')]]);
        $t = ShiftTemplate::create(['code' => strtoupper($request->input('code'))] + $this->attrs($request));
        $publications->master('shift_template', $t->id);
        Audit::record('shift_template.created', $t, $request->user()->id, after: $t->only(['code', 'name', 'starts_at', 'ends_at']));

        return back()->with('ok', "Template {$t->name} dibuat dan dikirim ke outlet.");
    }

    public function update(Request $request, ShiftTemplate $template, Publications $publications): RedirectResponse
    {
        $template->update($this->attrs($request));
        $publications->master('shift_template', $template->id);
        PublicationVersion::where('entity_type', 'shift_template')->where('entity_key', (string) $template->id)->latest('id')->first()?->update(['outlet_ids' => null]);

        return back()->with('ok', "Template {$template->name} diperbarui.");
    }

    private function attrs(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'outlet_ids' => ['nullable', 'array'],
            'outlet_ids.*' => ['integer', 'exists:outlets,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'break_minutes' => $data['break_minutes'] ?? 60,
            'outlet_ids' => empty($data['outlet_ids']) ? null : array_map('intval', $data['outlet_ids']),
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
