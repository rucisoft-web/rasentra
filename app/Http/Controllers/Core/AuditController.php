<?php

namespace App\Http\Controllers\Core;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Audit log append-only: override harga, void, approval, publikasi dan perubahan master. */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $events = AuditEvent::query()
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($request->integer('actor'), fn ($q, $id) => $q->where('actor_id', $id))
            ->when($request->query('outlet') === 'hq', fn ($q) => $q->whereNull('outlet_id'))
            ->when(is_numeric($request->query('outlet')), fn ($q) => $q->where('outlet_id', (int) $request->query('outlet')))
            ->when($request->query('from'), fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($request->query('to'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        $userIds = $events->getCollection()->flatMap(fn ($e) => [$e->actor_id, $e->approver_id])->filter()->unique();

        return view('core.audit', [
            'title' => 'Audit log',
            'events' => $events,
            'users' => User::whereIn('id', $userIds)->pluck('name', 'id'),
            'actors' => User::whereIn('id', AuditEvent::query()->whereNotNull('actor_id')->distinct()->pluck('actor_id'))->orderBy('name')->get(['id', 'name']),
            'actions' => AuditEvent::query()->distinct()->orderBy('action')->pluck('action'),
            'outlets' => Outlet::orderBy('code')->get(['id', 'code', 'name']),
            'outletCodes' => Outlet::pluck('code', 'id'),
        ]);
    }
}
