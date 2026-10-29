@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Http\Controllers\Core\MonitoringController', 'M')
@section('content')
<p class="muted">Setiap Rasentra Lokal mengirim transaksi lewat outbox; Rasentra Central menerimanya di inbox secara idempoten. Kolom antrean berasal dari heartbeat server outlet. Cursor publikasi pusat saat ini: <b>{{ $cursor ?? 0 }}</b>. Kesehatan lengkap per outlet ada di <a href="{{ route('core.central') }}">Rasentra Central</a>.</p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Outlet</th><th>Server Lokal</th><th>Event terakhir diterima</th><th class="n">Event 24 jam</th><th class="n">Menunggu posting</th><th class="n">Ditolak</th><th class="n">Antre / gagal / parked</th><th class="n">Promo belum diterima</th></tr></thead>
  <tbody>
  @foreach ($outlets as $o)
    @php $in = $inbox[$o->id] ?? null; $st = $states[$o->id] ?? null; $srv = $servers[$o->id] ?? null; @endphp
    <tr>
      <td><b>{{ $o->code }}</b> {{ $o->name }}</td>
      <td>@if($srv)<span class="mono">{{ $srv->code }}</span><div class="muted small">terlihat {{ M::ago($srv->last_seen_at) }}</div>@else<span class="muted">belum terdaftar</span>@endif</td>
      <td>{{ $in ? Fmt::dt($in->last_at) : '-' }}@if($in)<div class="muted small">{{ M::ago($in->last_at) }}</div>@endif</td>
      <td class="n">{{ $in->day_count ?? 0 }}</td>
      <td class="n">{{ $in->waiting ?? 0 }}</td>
      <td class="n">@if(($in->rejected ?? 0) > 0)<span class="b err">{{ $in->rejected }}</span>@else 0 @endif</td>
      <td class="n">@if($st){{ $st->outbox_pending }} / {{ $st->outbox_failed }} / @if($st->outbox_parked > 0)<span class="b err">{{ $st->outbox_parked }}</span>@else 0 @endif
        <div class="muted small">@if($st->outbox_oldest_at)tertua {{ M::ago($st->outbox_oldest_at) }} · @endif heartbeat {{ M::ago($st->last_heartbeat_at) }}</div>@else<span class="muted">belum ada heartbeat</span>@endif</td>
      <td class="n">{{ $receipts[$o->id] ?? 0 }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>

<h2>Event gagal kirim di instalasi ini</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Waktu</th><th>Outlet</th><th>Event</th><th>Status</th><th class="n">Percobaan</th><th>Error terakhir</th><th></th></tr></thead>
  <tbody>
  @forelse ($problems as $e)
    <tr>
      <td>{{ Fmt::dt($e->occurred_at) }}</td>
      <td>{{ $outlets->firstWhere('id', $e->outlet_id)?->code }}</td>
      <td class="mono small">{{ $e->event_type }}<br>{{ $e->entity_id }}</td>
      <td><span @class(['b', 'err' => $e->status === 'parked', 'warn' => $e->status === 'failed'])>{{ $e->status }}</span></td>
      <td class="n">{{ $e->attempts }}</td>
      <td class="small">{{ \Illuminate\Support\Str::limit($e->last_error, 140) }}</td>
      <td><form class="inline" method="post" action="{{ route('core.monitor.outbox.retry', $e) }}">@csrf<button class="btn alt sm">Kirim ulang</button></form></td>
    </tr>
  @empty
    <tr><td colspan="7" class="muted">Tidak ada event yang gagal.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
@endsection
