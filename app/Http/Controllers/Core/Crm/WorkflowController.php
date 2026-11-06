<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Crm\Phone;
use App\Domain\Crm\WorkflowEngine;
use App\Http\Controllers\Controller;
use App\Models\Crm\Branch;
use App\Models\Crm\Community;
use App\Models\Crm\Customer;
use App\Models\Crm\Segment;
use App\Models\Crm\Tag;
use App\Models\Crm\WaTemplate;
use App\Models\Crm\Workflow;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Rasentra CRM: chat automation workflow (pemicu + rangkaian langkah). */
class WorkflowController extends Controller
{
    public const MAX_STEPS = 8;

    public function index(): View
    {
        return view('core.crm.workflows.index', [
            'title' => 'Workflow otomatis',
            'workflows' => Workflow::withCount([
                'runs',
                'runs as waiting_count' => fn ($q) => $q->where('status', 'waiting'),
                'runs as failed_count' => fn ($q) => $q->where('status', 'failed'),
            ])->orderByDesc('is_active')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Workflow(['trigger' => 'keyword', 'trigger_config' => [], 'steps' => [], 'cooldown_days' => 0]));
    }

    public function edit(Workflow $workflow): View
    {
        return $this->form($workflow);
    }

    public function store(Request $request): RedirectResponse
    {
        $workflow = Workflow::create($this->validated($request) + ['is_active' => false, 'created_by' => $request->user()->id]);
        Audit::record('crm.workflow.created', $workflow, $request->user()->id, after: $workflow->only(['name', 'trigger', 'steps']));

        return redirect()->route('core.crm.workflows.show', $workflow)->with('ok', 'Workflow disimpan dalam keadaan nonaktif. Periksa langkahnya lalu aktifkan.');
    }

    public function update(Request $request, Workflow $workflow): RedirectResponse
    {
        $before = $workflow->only(['name', 'trigger', 'trigger_config', 'steps']);
        $workflow->update($this->validated($request));
        Audit::record('crm.workflow.updated', $workflow, $request->user()->id, $before, $workflow->only(['name', 'trigger', 'trigger_config', 'steps']));

        return redirect()->route('core.crm.workflows.show', $workflow)->with('ok', 'Workflow diperbarui.');
    }

    public function toggle(Request $request, Workflow $workflow): RedirectResponse
    {
        if (! $workflow->is_active && empty($workflow->steps)) {
            return back()->with('error', 'Tambahkan minimal satu langkah sebelum mengaktifkan workflow.');
        }
        $workflow->update(['is_active' => ! $workflow->is_active]);
        if (! $workflow->is_active) {
            // Run yang sedang menunggu ikut dihentikan agar tidak ada kiriman setelah workflow dimatikan.
            $workflow->runs()->whereIn('status', ['running', 'waiting'])->update(['status' => 'cancelled', 'resume_at' => null]);
        }
        Audit::record($workflow->is_active ? 'crm.workflow.activated' : 'crm.workflow.deactivated', $workflow, $request->user()->id);

        return back()->with('ok', $workflow->is_active ? 'Workflow aktif.' : 'Workflow dinonaktifkan.');
    }

    public function show(Workflow $workflow): View
    {
        return view('core.crm.workflows.show', [
            'title' => $workflow->name,
            'workflow' => $workflow,
            'runs' => $workflow->runs()->with('customer:id,name,phone')->latest('id')->paginate(30),
            'lookups' => $this->lookups(),
        ]);
    }

    private function form(Workflow $workflow): View
    {
        return view('core.crm.workflows.form', [
            'title' => $workflow->exists ? 'Ubah workflow' : 'Workflow baru',
            'workflow' => $workflow,
            'maxSteps' => self::MAX_STEPS,
        ] + $this->lookups());
    }

    private function lookups(): array
    {
        return [
            'templates' => WaTemplate::where('status', 'APPROVED')->orderBy('name')->get(['id', 'name', 'category', 'header_type', 'body_params']),
            'communities' => Community::orderBy('name')->get(['id', 'name']),
            'segments' => Segment::orderBy('name')->get(['id', 'name']),
            'tags' => Tag::orderBy('name')->get(['id', 'name']),
            'branches' => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'agents' => User::whereIn('role', ['admin', 'hq_crm'])->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(array_keys(Workflow::TRIGGERS))],
            'keywords' => ['nullable', 'required_if:trigger,keyword', 'string', 'max:300'],
            'days' => ['nullable', 'required_if:trigger,inactive', 'integer', 'min:1', 'max:730'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'community_id' => ['nullable', 'exists:crm_communities,id'],
            'crm_branch_id' => ['nullable', 'exists:crm_branches,id'],
            'segment_id' => ['nullable', 'exists:crm_segments,id'],
            'cooldown_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'steps' => ['array', 'max:'.self::MAX_STEPS],
            'steps.*.type' => ['nullable', Rule::in(array_keys(Workflow::STEP_TYPES))],
            'steps.*.template_id' => ['nullable', 'exists:crm_wa_templates,id'],
            'steps.*.params' => ['nullable', 'string', 'max:500'],
            'steps.*.media_url' => ['nullable', 'url', 'max:500'],
            'steps.*.body' => ['nullable', 'string', 'max:1000'],
            'steps.*.tag_id' => ['nullable', 'exists:crm_tags,id'],
            'steps.*.community_id' => ['nullable', 'exists:crm_communities,id'],
            'steps.*.user_id' => ['nullable', 'exists:users,id'],
            'steps.*.hours' => ['nullable', 'integer', 'min:1', 'max:2160'],
        ]);

        $trigger = $data['trigger'];
        $config = array_filter([
            'keywords' => $trigger === 'keyword' ? mb_strtolower(trim($data['keywords'] ?? '')) : null,
            'days' => $trigger === 'inactive' ? (int) $data['days'] : null,
            'min_amount' => $trigger === 'purchase' && ! empty($data['min_amount']) ? (float) $data['min_amount'] : null,
            'community_id' => $trigger === 'joined_community' && ! empty($data['community_id']) ? (int) $data['community_id'] : null,
            'crm_branch_id' => $trigger === 'new_customer' && ! empty($data['crm_branch_id']) ? (int) $data['crm_branch_id'] : null,
            'segment_id' => ! empty($data['segment_id']) ? (int) $data['segment_id'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        $steps = [];
        $errors = [];
        foreach (array_values($data['steps'] ?? []) as $i => $s) {
            $type = $s['type'] ?? null;
            if (! $type) {
                continue;
            }
            $n = $i + 1;
            $step = match ($type) {
                'send_template' => ['type' => $type, 'template_id' => (int) ($s['template_id'] ?? 0),
                    'params' => array_values(array_filter(array_map('trim', explode('|', (string) ($s['params'] ?? ''))), fn ($p) => $p !== '')),
                    'media_url' => $s['media_url'] ?? null],
                'send_text' => ['type' => $type, 'body' => trim((string) ($s['body'] ?? ''))],
                'wait' => ['type' => $type, 'hours' => (int) ($s['hours'] ?? 0)],
                'add_tag' => ['type' => $type, 'tag_id' => (int) ($s['tag_id'] ?? 0)],
                'join_community' => ['type' => $type, 'community_id' => (int) ($s['community_id'] ?? 0)],
                'assign' => ['type' => $type, 'user_id' => ! empty($s['user_id']) ? (int) $s['user_id'] : null],
            };
            $missing = match ($type) {
                'send_template' => $step['template_id'] ? null : 'pilih template',
                'send_text' => $step['body'] !== '' ? null : 'isi pesan',
                'wait' => $step['hours'] > 0 ? null : 'isi lama tunggu',
                'add_tag' => $step['tag_id'] ? null : 'pilih tag',
                'join_community' => $step['community_id'] ? null : 'pilih komunitas',
                default => null,
            };
            if ($missing) {
                $errors["steps.$i.type"] = "Langkah $n: $missing.";
            }
            if ($type === 'send_template' && $step['template_id']) {
                $template = WaTemplate::find($step['template_id']);
                if (in_array($template?->header_type, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && empty($step['media_url'])) {
                    $errors["steps.$i.media_url"] = "Langkah $n: template {$template->name} memakai header ".strtolower($template->header_type).', isi URL materi.';
                }
            }
            $steps[] = $step;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $data['name'],
            'trigger' => $trigger,
            'trigger_config' => $config,
            'steps' => $steps,
            'cooldown_days' => (int) ($data['cooldown_days'] ?? 0),
        ];
    }

    /** Uji coba manual: jalankan workflow untuk satu pelanggan (mis. nomor staf sendiri). */
    public function test(Request $request, Workflow $workflow, WorkflowEngine $engine): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $customer = Customer::where('phone', Phone::normalize($data['phone']) ?? '')->first();
        if (! $customer) {
            return back()->withErrors(['phone' => 'Nomor ini belum ada di database pelanggan.']);
        }
        $run = $engine->start($workflow, $customer);

        return back()->with($run ? 'ok' : 'error', $run
            ? "Uji coba dijalankan untuk {$customer->name}: ".($run->fresh()->status === 'waiting' ? 'sedang menunggu langkah berikutnya.' : 'status '.$run->fresh()->status.'.')
            : 'Tidak dijalankan: pelanggan ini masih dalam masa jeda atau run sebelumnya belum selesai.');
    }
}
