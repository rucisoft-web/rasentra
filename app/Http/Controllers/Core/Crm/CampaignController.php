<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Audit\Audit;
use App\Domain\Crm\CampaignService;
use App\Domain\Crm\Messenger;
use App\Http\Controllers\Controller;
use App\Models\Crm\Campaign;
use App\Models\Crm\Community;
use App\Models\Crm\Segment;
use App\Models\Crm\WaChannel;
use App\Models\Crm\WaTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/** Rasentra CRM: kampanye iklan/broadcast WhatsApp ke komunitas dan segmen. */
class CampaignController extends Controller
{
    public function index(): View
    {
        return view('core.crm.campaigns.index', [
            'title' => 'Kampanye & iklan WhatsApp',
            'campaigns' => Campaign::with(['template', 'creator:id,name'])->latest()->paginate(30),
        ]);
    }

    public function create(Request $request, CampaignService $service): View
    {
        $campaign = new Campaign([
            'audience' => ['community_ids' => array_filter([(int) $request->query('community')]), 'segment_id' => $request->query('segment')],
            'crm_wa_channel_id' => WaChannel::where('is_default', true)->value('id'),
        ]);

        return view('core.crm.campaigns.form', [
            'title' => 'Kampanye baru',
            'campaign' => $campaign,
            'channels' => WaChannel::orderBy('name')->get(),
            'templates' => WaTemplate::where('status', 'APPROVED')->orderBy('name')->get(),
            'communities' => Community::withCount(['members as opted_in_count' => fn ($q) => $q->where('wa_status', 'opted_in')])->orderBy('name')->get(),
            'segments' => Segment::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, CampaignService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'crm_wa_template_id' => ['required', 'exists:crm_wa_templates,id'],
            'community_ids' => ['array'], 'community_ids.*' => ['integer', 'exists:crm_communities,id'],
            'segment_id' => ['nullable', 'exists:crm_segments,id'],
            'params' => ['array'], 'params.*' => ['nullable', 'string', 'max:500'],
            'media_url' => ['nullable', 'url', 'max:500'],
            'media' => ['nullable', 'file', 'max:16384', 'mimes:jpg,jpeg,png,mp4,pdf'],
            'scheduled_at' => ['nullable', 'date'],
            'action' => ['required', 'in:draft,launch'],
        ]);
        $template = WaTemplate::findOrFail($data['crm_wa_template_id']);
        if (empty($data['community_ids']) && empty($data['segment_id'])) {
            return back()->withInput()->withErrors(['community_ids' => 'Pilih minimal satu komunitas atau segmen penerima.']);
        }
        $mediaUrl = $data['media_url'] ?? null;
        if ($request->hasFile('media')) {
            // Meta mengambil media dari URL publik: simpan di disk public (perlu php artisan storage:link).
            $mediaUrl = Storage::disk('public')->url($request->file('media')->store('crm-media', 'public'));
        }
        if (in_array($template->header_type, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && ! $mediaUrl) {
            return back()->withInput()->withErrors(['media' => 'Template ini memakai header '.strtolower($template->header_type).'. Unggah file atau isi URL materi iklan.']);
        }
        $campaign = Campaign::create([
            'name' => $data['name'],
            'crm_wa_channel_id' => $template->crm_wa_channel_id,
            'crm_wa_template_id' => $template->id,
            'audience' => ['community_ids' => array_map('intval', $data['community_ids'] ?? []), 'segment_id' => $data['segment_id'] ?? null],
            'params' => array_slice(array_values($data['params'] ?? []), 0, $template->body_params),
            'media_url' => $mediaUrl,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);
        Audit::record('crm.campaign.created', $campaign, $request->user()->id, after: $campaign->only(['name', 'audience', 'scheduled_at']));
        if ($data['action'] === 'launch') {
            $service->launch($campaign);
            Audit::record('crm.campaign.launched', $campaign, $request->user()->id, after: ['status' => $campaign->status]);
        }

        return redirect()->route('core.crm.campaigns.show', $campaign)->with('ok', match ($campaign->status) {
            'scheduled' => 'Kampanye dijadwalkan.',
            'sending' => 'Kampanye mulai dikirim bertahap.',
            default => 'Draf kampanye disimpan.',
        });
    }

    public function show(Campaign $campaign, CampaignService $service): View
    {
        $campaign->load(['template', 'channel', 'creator']);

        return view('core.crm.campaigns.show', [
            'title' => $campaign->name,
            'campaign' => $campaign,
            'stats' => CampaignService::stats($campaign),
            'audienceCount' => in_array($campaign->status, ['draft', 'scheduled'], true) ? $service->audience($campaign)->count() : null,
            'preview' => Messenger::render($campaign->template->body, array_map(fn ($p) => strtr((string) $p, ['{name}' => 'Budi Santoso', '{first_name}' => 'Budi', '{outlet}' => 'Outlet']), $campaign->params ?? [])),
            'communities' => Community::whereIn('id', $campaign->audience['community_ids'] ?? [])->pluck('name'),
            'segment' => ! empty($campaign->audience['segment_id']) ? Segment::find($campaign->audience['segment_id']) : null,
            'failures' => $campaign->recipients()->with('customer:id,name,phone')->where('status', 'failed')->limit(50)->get(),
        ]);
    }

    public function launch(Request $request, Campaign $campaign, CampaignService $service): RedirectResponse
    {
        $service->launch($campaign);
        Audit::record('crm.campaign.launched', $campaign, $request->user()->id, after: ['status' => $campaign->status]);

        return back()->with('ok', $campaign->status === 'scheduled' ? 'Kampanye dijadwalkan.' : 'Kampanye mulai dikirim bertahap.');
    }

    public function cancel(Request $request, Campaign $campaign, CampaignService $service): RedirectResponse
    {
        $service->cancel($campaign);
        Audit::record('crm.campaign.cancelled', $campaign, $request->user()->id);

        return back()->with('ok', 'Kampanye dibatalkan; pesan yang belum terkirim tidak akan dikirim.');
    }
}
