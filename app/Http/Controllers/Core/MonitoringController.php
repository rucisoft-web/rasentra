<?php

namespace App\Http\Controllers\Core;

use App\Domain\Audit\Audit;
use App\Domain\Integration\Sap\SapPostingService;
use App\Http\Controllers\Controller;
use App\Models\BusinessUnit;
use App\Models\Device;
use App\Models\InboxEvent;
use App\Models\IntegrationException;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\OutletSyncState;
use App\Models\PromotionTarget;
use App\Models\SapDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Monitoring Rasentra Central: arus event dari tiap Rasentra Lokal, antrean yang macet,
 * dokumen SAP yang gagal atau perlu dicek, dan masalah integrasi yang perlu ditindak.
 */
class MonitoringController extends Controller
{
    public const SAP_STATUS = [
        'posted' => ['Terposting', 'ok'],
        'pending' => ['Menunggu', 'info'],
        'posting' => ['Sedang diposting', 'info'],
        'waiting_dependency' => ['Menunggu dokumen lain', 'info'],
        'validated' => ['Tervalidasi', 'info'],
        'partially_posted' => ['Parsial', 'warn'],
        'needs_review' => ['Perlu dicek', 'warn'],
        'failed' => ['Gagal', 'err'],
    ];

    public function sync(): View
    {
        $since = now()->subDay();
        $inbox = DB::table('inbox_events')->groupBy('outlet_id')
            ->selectRaw("outlet_id, MAX(created_at) as last_at, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as day_count, SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected, SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as waiting", [$since])
            ->get()->keyBy('outlet_id');
        // Antrean outbox tiap outlet dilaporkan Rasentra Lokal lewat heartbeat.
        $states = OutletSyncState::all()->keyBy('outlet_id');
        $servers = Device::where('app', 'outlet_server')->get()->keyBy('outlet_id');
        $receipts = PromotionTarget::where('ack_status', 'pending')->groupBy('outlet_id')->selectRaw('outlet_id, COUNT(*) as n')->pluck('n', 'outlet_id');

        return view('core.monitor.sync', [
            'title' => 'Sinkronisasi outlet',
            'outlets' => Outlet::where('is_active', true)->orderBy('code')->get(),
            'inbox' => $inbox,
            'states' => $states,
            'servers' => $servers,
            'receipts' => $receipts,
            'problems' => OutboxEvent::whereIn('status', ['failed', 'parked'])->orderByDesc('updated_at')->limit(50)->get(),
            'cursor' => DB::table('publication_versions')->max('id'),
            'profile' => config('rasentra.profile'),
        ]);
    }

    public function retryOutbox(Request $request, OutboxEvent $event): RedirectResponse
    {
        abort_unless(in_array($event->status, ['failed', 'parked'], true), 409);
        $event->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null]);
        Audit::record('outbox.requeued', "outbox_event:{$event->event_id}", $request->user()->id, outletId: $event->outlet_id, before: ['last_error' => $event->last_error]);

        return back()->with('ok', 'Event dikembalikan ke antrean kirim.');
    }

    public function sap(Request $request): View
    {
        $company = $request->query('company');
        $docs = SapDocument::query()
            ->when($company, fn ($q, $c) => $q->where('company_db', $c))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'needs_review' THEN 0 WHEN 'failed' THEN 1 WHEN 'partially_posted' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')->paginate(50)->withQueryString();
        $orders = DB::table('hq_orders')->join('outlets', 'outlets.id', '=', 'hq_orders.outlet_id')
            ->whereIn('hq_orders.id', $docs->pluck('source_id'))->get(['hq_orders.id', 'hq_orders.doc_no', 'hq_orders.net_amount', 'outlets.code'])->keyBy('id');

        return view('core.monitor.sap', [
            'title' => 'Posting SAP',
            'docs' => $docs,
            'orders' => $orders,
            'counts' => SapDocument::when($company, fn ($q, $c) => $q->where('company_db', $c))->groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status'),
            'companies' => BusinessUnit::whereNotNull('sap_company_db')->orderBy('id')->get(),
            'company' => $company,
            'waiting' => InboxEvent::whereIn('event_type', ['order.finalized', 'order.returned'])->where('status', 'accepted')->count(),
            'statuses' => self::SAP_STATUS,
        ]);
    }

    public function sapDocument(SapDocument $document): View
    {
        return view('core.monitor.sap-show', [
            'title' => "Dokumen SAP {$document->sap_object}",
            'doc' => $document,
            'order' => DB::table('hq_orders')->where('id', $document->source_id)->first(),
            'related' => SapDocument::where('source_type', $document->source_type)->where('source_id', $document->source_id)->get(),
            'statuses' => self::SAP_STATUS,
        ]);
    }

    public function postPending(Request $request, SapPostingService $sap): RedirectResponse
    {
        $summary = $sap->postInbox();
        Audit::record('sap.post_run', 'sap:inbox', $request->user()->id, after: $summary);

        return back()->with('ok', $summary === [] ? 'Tidak ada penjualan yang menunggu posting.' : 'Hasil posting: '.collect($summary)->map(fn ($n, $s) => "{$s} {$n}")->implode(', '));
    }

    public function retrySap(Request $request, SapDocument $document, SapPostingService $sap): RedirectResponse
    {
        $type = $document->source_type === 'sales_return' ? 'order.returned' : 'order.finalized';
        $event = InboxEvent::where('event_type', $type)->where('entity_id', $document->source_id)->latest('id')->first();
        abort_if($event === null, 404, 'Event sumber tidak ditemukan di inbox.');

        // Posting ulang selalu memeriksa U_POS_SRC di SAP lebih dulu, jadi tidak membuat dokumen ganda.
        $result = $sap->postEvent($event);
        if ($result['status'] === 'posted') {
            $event->update(['status' => 'processed']);
        }
        Audit::record('sap.retry', $document, $request->user()->id, after: $result);

        return back()->with($result['status'] === 'posted' ? 'ok' : 'error', 'Hasil: '.$result['status'].(isset($result['missing']) ? ' (mapping kurang: '.implode(', ', $result['missing']).')' : ''));
    }

    public function exceptions(Request $request): View
    {
        $status = $request->query('status', 'open');
        $items = IntegrationException::query()->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest()->paginate(50)->withQueryString();

        return view('core.monitor.exceptions', [
            'title' => 'Masalah integrasi',
            'items' => $items,
            'status' => $status,
            'orders' => DB::table('hq_orders')->join('outlets', 'outlets.id', '=', 'hq_orders.outlet_id')
                ->whereIn('hq_orders.id', $items->where('source_type', 'order')->pluck('source_id'))
                ->get(['hq_orders.id', 'hq_orders.doc_no', 'outlets.code'])->keyBy('id'),
        ]);
    }

    public function resolveException(Request $request, IntegrationException $exception): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:255']]);
        $exception->update(['status' => 'resolved']);
        Audit::record('integration_exception.resolved', $exception, $request->user()->id, reason: $data['note']);

        return back()->with('ok', 'Ditandai selesai.');
    }

    public static function ago(?string $time): string
    {
        return $time ? Carbon::parse($time)->diffForHumans() : '-';
    }
}
