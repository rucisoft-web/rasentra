<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Crm\AiAssistant;
use App\Domain\Crm\Messenger;
use App\Http\Controllers\Controller;
use App\Models\Crm\Conversation;
use App\Models\Crm\Customer;
use App\Models\Crm\WaChannel;
use App\Models\Crm\WaTemplate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Rasentra CRM: inbox WhatsApp bersama untuk tim CS (balas, template, tugaskan, tutup). */
class InboxController extends Controller
{
    public function index(Request $request): View
    {
        $filter = $request->query('filter', 'open');
        $q = Conversation::with(['customer', 'channel', 'assignee'])->orderByDesc('last_message_at');
        match ($filter) {
            'mine' => $q->where('assigned_to', $request->user()->id)->where('status', 'open'),
            'unread' => $q->where('unread_count', '>', 0),
            'handoff' => $q->where('ai_handoff', true)->where('status', 'open'),
            'closed' => $q->where('status', 'closed'),
            'all' => null,
            default => $q->where('status', 'open'),
        };
        if ($term = trim((string) $request->query('q'))) {
            $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $term).'%'));
        }

        return view('core.crm.inbox.index', [
            'title' => 'Inbox WhatsApp',
            'conversations' => $q->paginate(40)->withQueryString(),
            'filter' => $filter,
            'channels' => WaChannel::count(),
        ]);
    }

    public function show(Conversation $conversation): View
    {
        $conversation->load(['customer.tags', 'channel', 'assignee']);
        if ($conversation->unread_count) {
            $conversation->update(['unread_count' => 0]);
        }

        return view('core.crm.inbox.show', [
            'title' => $conversation->customer->name,
            'c' => $conversation,
            'messages' => $conversation->messages()->with('sender:id,name')->orderByDesc('id')->limit(200)->get()->reverse(),
            'windowOpen' => Messenger::windowOpen($conversation),
            'templates' => WaTemplate::where('crm_wa_channel_id', $conversation->crm_wa_channel_id)->where('status', 'APPROVED')->orderBy('name')->get(),
            'agents' => User::whereIn('role', ['admin', 'hq_crm'])->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'aiEnabled' => app(AiAssistant::class)->enabledFor($conversation),
        ]);
    }

    public function start(Customer $customer): RedirectResponse
    {
        $channel = WaChannel::where('is_default', true)->first() ?? WaChannel::first();
        if (! $channel) {
            return back()->with('error', 'Belum ada nomor WhatsApp. Tambahkan di menu Nomor & template WhatsApp.');
        }

        return redirect()->route('core.crm.inbox.show', app(Messenger::class)->conversation($customer, $channel));
    }

    public function reply(Request $request, Conversation $conversation, Messenger $messenger): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4096']]);
        $messenger->sendText($conversation, $data['body'], $request->user()->id);
        $conversation->update(['ai_handoff' => false, 'ai_handoff_reason' => null, 'ai_suggestion' => null]
            + ($conversation->assigned_to ? [] : ['assigned_to' => $request->user()->id]));

        return back();
    }

    public function template(Request $request, Conversation $conversation, Messenger $messenger): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['required', Rule::exists('crm_wa_templates', 'id')->where('crm_wa_channel_id', $conversation->crm_wa_channel_id)],
            'params' => ['array'], 'params.*' => ['nullable', 'string', 'max:1024'],
            'media_url' => ['nullable', 'url', 'max:500'],
        ]);
        $messenger->sendTemplate($conversation->customer, WaTemplate::findOrFail($data['template_id']), array_values($data['params'] ?? []), $data['media_url'] ?? null, $request->user()->id);

        return back()->with('ok', 'Template terkirim.');
    }

    /** Minta saran balasan dan skor lead dari asisten AI (knowledge base + riwayat chat). */
    public function assist(Conversation $conversation, AiAssistant $ai): RedirectResponse
    {
        $r = $ai->analyze($conversation);

        return back()->with('ok', $r['needs_human']
            ? 'Saran AI siap, tetapi AI menyarankan CS yang menjawab: '.($r['handoff_reason'] ?: 'informasi tidak ada di knowledge base').'.'
            : 'Saran balasan AI siap. Periksa sebelum dikirim.');
    }

    public function update(Request $request, Conversation $conversation): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);
        $conversation->update(array_filter($data, fn ($v) => $v !== null) + ($request->has('assigned_to') ? ['assigned_to' => $data['assigned_to']] : []));

        return back()->with('ok', 'Percakapan diperbarui.');
    }
}
