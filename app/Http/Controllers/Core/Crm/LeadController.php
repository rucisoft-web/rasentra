<?php

namespace App\Http\Controllers\Core\Crm;

use App\Domain\Crm\AiAssistant;
use App\Http\Controllers\Controller;
use App\Models\Crm\Conversation;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Rasentra CRM: daftar lead hasil identifikasi AI dari percakapan WhatsApp. */
class LeadController extends Controller
{
    public function index(Request $request): View
    {
        $stage = $request->query('stage');
        $intent = $request->query('intent');
        $q = Conversation::with(['customer', 'assignee:id,name'])->whereNotNull('lead_score')
            ->when(array_key_exists((string) $stage, AiAssistant::STAGES), fn ($q) => $q->where('lead_stage', $stage))
            ->when(array_key_exists((string) $intent, AiAssistant::INTENTS), fn ($q) => $q->where('lead_intent', $intent))
            ->orderByDesc('lead_score')->orderByDesc('last_inbound_at');

        return view('core.crm.leads.index', [
            'title' => 'Lead dari WhatsApp',
            'leads' => $q->paginate(40)->withQueryString(),
            'counts' => Conversation::whereNotNull('lead_stage')->selectRaw('lead_stage, COUNT(*) AS n, COALESCE(SUM(lead_value), 0) AS v')->groupBy('lead_stage')->get()->keyBy('lead_stage'),
            'stage' => $stage,
            'intent' => $intent,
        ]);
    }
}
