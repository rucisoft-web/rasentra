<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Crm\Messenger;
use App\Http\Controllers\Controller;
use App\Models\Crm\Branch;
use App\Models\Crm\WaChannel;
use App\Models\Crm\WaTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Rasentra CRM: nomor WhatsApp Business (Cloud API) dan template pesan yang disetujui Meta. */
class WhatsAppController extends Controller
{
    public function index(): View
    {
        return view('core.crm.whatsapp.index', [
            'title' => 'Nomor & template WhatsApp',
            'channels' => WaChannel::with('branch')->orderBy('name')->get(),
            'templates' => WaTemplate::with('channel')->orderBy('crm_wa_channel_id')->orderBy('name')->get(),
            'branches' => Branch::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'webhookUrl' => route('crm.whatsapp.webhook'),
            'webhookReady' => filled(config('services.whatsapp.verify_token')) && filled(config('services.whatsapp.app_secret')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $channel = WaChannel::create($this->validated($request));
        $this->defaultOnly($channel);
        Audit::record('crm.wa_channel.created', $channel, $request->user()->id, after: $channel->only(['name', 'phone_number_id', 'waba_id']));

        return back()->with('ok', "Nomor {$channel->name} ditambahkan. Klik Sinkron template untuk mengambil template dari Meta.");
    }

    public function update(Request $request, WaChannel $channel): RedirectResponse
    {
        $data = $this->validated($request, $channel);
        if (blank($data['access_token'] ?? null)) {
            unset($data['access_token']);
        }
        $channel->update($data);
        $this->defaultOnly($channel);
        Audit::record('crm.wa_channel.updated', $channel, $request->user()->id, after: $channel->only(['name', 'phone_number_id', 'waba_id']));

        return back()->with('ok', 'Pengaturan nomor disimpan.');
    }

    public function syncTemplates(Request $request, WaChannel $channel, Messenger $messenger): RedirectResponse
    {
        $n = $messenger->syncTemplates($channel);

        return back()->with('ok', "{$n} template diambil dari Meta untuk {$channel->name}.");
    }

    private function validated(Request $request, ?WaChannel $channel = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'display_phone' => ['nullable', 'string', 'max:30'],
            'phone_number_id' => ['required', 'regex:/^\d{6,30}$/', Rule::unique('crm_wa_channels', 'phone_number_id')->ignore($channel?->id)],
            'waba_id' => ['required', 'regex:/^\d{6,30}$/'],
            'access_token' => [$channel ? 'nullable' : 'required', 'string', 'max:1000'],
            'crm_branch_id' => ['nullable', 'exists:crm_branches,id'],
            'send_per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_default' => ['boolean'],
        ]);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);

        return $data;
    }

    private function defaultOnly(WaChannel $channel): void
    {
        if ($channel->is_default || WaChannel::where('is_default', true)->doesntExist()) {
            WaChannel::whereKeyNot($channel->id)->update(['is_default' => false]);
            $channel->update(['is_default' => true]);
        }
    }
}
