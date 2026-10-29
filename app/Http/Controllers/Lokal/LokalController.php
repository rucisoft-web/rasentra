<?php

namespace App\Http\Controllers\Lokal;

use App\Domain\Audit\Audit;
use App\Domain\Lokal\Backup;
use App\Domain\Lokal\LokalStatus;
use App\Domain\Sync\ChangePuller;
use App\Domain\Sync\DayCloser;
use App\Domain\Sync\HeartbeatSender;
use App\Domain\Sync\OutboxDispatcher;
use App\Http\Controllers\Controller;
use App\Models\CookingFeeRule;
use App\Models\OutboxEvent;
use App\Models\Outlet;
use App\Models\PriceVersion;
use App\Models\PromotionVersion;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Support\SystemState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Konsol Rasentra Lokal untuk kepala outlet: kondisi server, antrean kirim ke Central,
 * publikasi yang berlaku, tutup hari dan backup.
 */
class LokalController extends Controller
{
    public const STATUS = [
        'pending' => ['Antre', 'info'],
        'sending' => ['Sedang dikirim', 'info'],
        'failed' => ['Gagal, dicoba lagi', 'warn'],
        'parked' => ['Berhenti dicoba', 'err'],
        'held' => ['Ditahan', 'warn'],
        'acked' => ['Diterima Central', 'ok'],
    ];

    public function __construct(private readonly LokalStatus $status) {}

    public function index(Backup $backup): View|RedirectResponse
    {
        $outlet = $this->status->outlet();
        if ($outlet === null) {
            return redirect()->route('lokal.setup');
        }

        return view('lokal.index', [
            'title' => 'Status server',
            'outlet' => $outlet,
            'central' => $this->status->central(),
            'outbox' => $this->status->outbox(),
            'sync' => $this->status->sync(),
            'today' => $this->status->today($outlet),
            'shifts' => $this->status->shifts($outlet),
            'storage' => $this->status->storage(),
            'backups' => array_slice($backup->list(), 0, 5),
            'staff' => User::where('outlet_id', $outlet->id)->where('is_active', true)->count(),
        ]);
    }

    public function push(Request $request, OutboxDispatcher $dispatcher): RedirectResponse
    {
        $result = $dispatcher->dispatch();

        return back()->with(isset($result['error']) ? 'error' : 'ok', isset($result['error'])
            ? 'Central belum bisa dihubungi: '.$result['error'].'. Transaksi tetap aman di antrean.'
            : "{$result['sent']} event terkirim ke Central.");
    }

    public function pull(ChangePuller $puller): RedirectResponse
    {
        try {
            $result = $puller->pull();
        } catch (Throwable $e) {
            return back()->with('error', 'Central belum bisa dihubungi: '.$e->getMessage());
        }

        return back()->with(isset($result['error']) ? 'error' : 'ok', isset($result['error'])
            ? "Publikasi tertahan: {$result['error']}"
            : "{$result['applied']} publikasi diterapkan. Cursor sekarang {$result['cursor']}.");
    }

    public function heartbeat(HeartbeatSender $sender): RedirectResponse
    {
        $result = $sender->send();

        return back()->with($result['ok'] ? 'ok' : 'error', $result['ok']
            ? "Central terhubung. Selisih jam server ini {$result['clock_skew_seconds']} detik."
            : 'Central tidak terhubung: '.$result['error']);
    }

    public function outbox(Request $request): View
    {
        $status = $request->query('status', 'open');
        $events = OutboxEvent::query()
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['pending', 'sending', 'failed', 'parked', 'held']))
            ->when($status !== 'open' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('lokal.outbox', [
            'title' => 'Antrean kirim ke Central',
            'events' => $events,
            'status' => $status,
            'counts' => OutboxEvent::groupBy('status')->selectRaw('status, COUNT(*) as n')->pluck('n', 'status'),
            'statuses' => self::STATUS,
        ]);
    }

    public function retry(Request $request, OutboxEvent $event): RedirectResponse
    {
        abort_unless(in_array($event->status, ['failed', 'parked', 'held'], true), 409);
        $this->requeue($request, collect([$event]));

        return back()->with('ok', 'Event dikembalikan ke antrean. Tekan Kirim sekarang atau tunggu pengiriman otomatis.');
    }

    public function retryAll(Request $request): RedirectResponse
    {
        $events = OutboxEvent::whereIn('status', ['failed', 'parked'])->get();
        $this->requeue($request, $events);

        return back()->with('ok', "{$events->count()} event dikembalikan ke antrean.");
    }

    public function publications(): View
    {
        $outlet = $this->status->outlet();
        $outletId = $outlet?->id;

        return view('lokal.publications', [
            'title' => 'Harga, promo & resep yang berlaku',
            'prices' => PriceVersion::withCount('items')->where('status', 'published')
                ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId))->orderByDesc('effective_from')->get(),
            'promos' => PromotionVersion::with('promotion')->where('status', 'published')->whereHas('targets', fn ($q) => $q->where('outlet_id', $outletId))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->orderBy('starts_at')->get(),
            'recipes' => RecipeVersion::where('status', 'approved')->count(),
            'fees' => CookingFeeRule::where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId))
                ->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->orderBy('method')->get(),
            'sync' => $this->status->sync(),
            'log' => SystemState::get('sync:pull_log', []),
        ]);
    }

    public function closeDay(Request $request, DayCloser $closer): View
    {
        $outlet = $this->status->outlet();
        abort_if($outlet === null, 404);
        $today = Outlet::businessDate($outlet->id);
        $date = $request->query('date', $today);
        $data = validator(['date' => $date], ['date' => ['date_format:Y-m-d', 'before_or_equal:'.$today]])->validate();

        $history = OutboxEvent::where('event_type', 'business_day.closed')->where('outlet_id', $outlet->id)->orderByDesc('id')->limit(30)->get();

        return view('lokal.close-day', [
            'title' => 'Tutup hari',
            'outlet' => $outlet,
            'date' => $data['date'],
            'today' => $today,
            'summary' => $closer->summary($outlet, $data['date']),
            'closed' => $history->firstWhere(fn ($e) => $e->business_date->toDateString() === $data['date']),
            'history' => $history,
            'statuses' => self::STATUS,
        ]);
    }

    public function storeCloseDay(Request $request, DayCloser $closer, OutboxDispatcher $dispatcher): RedirectResponse
    {
        $outlet = $this->status->outlet();
        abort_if($outlet === null, 404);
        $today = Outlet::businessDate($outlet->id);
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today]]);

        $event = $closer->close($outlet, $data['date'], $request->user()->id);
        $dispatcher->dispatch();
        $delivered = $event->fresh()->status === 'acked';

        // Tutup hari tetap sah walau Central sedang terputus; ringkasan menunggu di antrean.
        return redirect()->route('lokal.close-day', ['date' => $data['date']])->with('ok', $delivered
            ? "Hari {$data['date']} ditutup (versi {$event->entity_version}) dan ringkasannya sudah diterima Central."
            : "Hari {$data['date']} ditutup. Ringkasan masuk antrean dan terkirim otomatis saat Central terhubung.");
    }

    public function backup(Request $request, Backup $backup): RedirectResponse
    {
        try {
            $result = $backup->run();
        } catch (Throwable $e) {
            return back()->with('error', 'Backup gagal: '.$e->getMessage());
        }
        Audit::record('lokal.backup', 'backup:'.$result['file'], $request->user()->id, after: $result);

        return back()->with('ok', "Backup {$result['file']} dibuat (".LokalStatus::bytes($result['bytes']).').');
    }

    public function health(): JsonResponse
    {
        return response()->json($this->status->health());
    }

    /** Indikator di layar Rasentra POS: Central terhubung atau tidak, dan jumlah transaksi yang antre. */
    public function posStatus(): JsonResponse
    {
        return response()->json($this->status->forPos());
    }

    private function requeue(Request $request, $events): void
    {
        foreach ($events as $event) {
            Audit::record('outbox.requeued', "outbox_event:{$event->event_id}", $request->user()->id, outletId: $event->outlet_id, before: ['status' => $event->status, 'last_error' => $event->last_error]);
            $event->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null]);
        }
    }
}
