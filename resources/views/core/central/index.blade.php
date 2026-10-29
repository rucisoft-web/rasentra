@extends('core.layout')
@use('App\Support\Fmt')
@use('App\Http\Controllers\Core\CentralController', 'C')
@section('content')
@php
    $healthLabel = ['ok' => ['Sehat', 'ok'], 'warn' => ['Perlu dilihat', 'warn'], 'err' => ['Bermasalah', 'err'], 'none' => ['Belum heartbeat', 'mute']];
    $sapOk = $companies->filter(fn ($c) => $c->last['ok'] ?? false)->count();
@endphp
<p class="muted">Rasentra Central menerima transaksi dari setiap Rasentra Lokal, menyebarkan harga, promo, resep dan data master lewat cursor publikasi, lalu memposting penjualan ke SAP B1 milik masing-masing CV. Profil instalasi ini: <span class="mono b {{ $profile === 'central' ? 'ok' : 'mute' }}">{{ $profile }}</span></p>

<div class="grid g4">
  <div class="card kpi"><div class="label">Server Lokal sehat</div><div class="value">{{ $outlets->where('health', 'ok')->count() }} / {{ $outlets->count() }}</div><div class="note">{{ $servers }} server outlet terdaftar</div></div>
  <div class="card kpi"><div class="label">Event masuk 1 jam</div><div class="value">{{ $inbox['hour'] }}</div><div class="note">{{ $inbox['day'] }} dalam 24 jam · terakhir {{ C::ago($inbox['last']) }}</div></div>
  <div class="card kpi"><div class="label">Cursor publikasi</div><div class="value">{{ $cursor }}</div><div class="note">{{ $outlets->sum('lag') }} publikasi belum diterapkan outlet</div></div>
  <div class="card kpi"><div class="label">Koneksi SAP</div><div class="value">{{ $sapOk }} / {{ $companies->count() }}</div><div class="note">{{ $inbox['waiting'] }} penjualan menunggu posting</div></div>
</div>

<h2>Rasentra Lokal per outlet</h2>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Outlet</th><th>Status</th><th>Heartbeat</th><th>Versi</th><th class="n">Selisih jam</th><th class="n">Antre / gagal / parked</th><th>Terkirim terakhir</th><th class="n">Cursor diterapkan</th><th class="n">Belum diterapkan</th><th>Shift buka</th></tr></thead>
  <tbody>
  @foreach ($outlets as $r)
    @php [$label, $tone] = $healthLabel[$r->health]; $s = $r->state; @endphp
    <tr>
      <td><b>{{ $r->outlet->code }}</b> {{ $r->outlet->name }}<div class="muted small">{{ $r->outlet->businessUnit?->name }} · {{ $r->outlet->timezone }}</div></td>
      <td><span class="b {{ $tone }}">{{ $label }}</span>@if($s?->last_error)<div class="small" style="color:var(--red)" title="{{ $s->last_error }}">{{ \Illuminate\Support\Str::limit($s->last_error, 60) }}</div>@endif</td>
      <td>@if($s?->last_heartbeat_at){{ C::ago($s->last_heartbeat_at) }}<div class="muted small mono">{{ $s->device?->code }}</div>@else<span class="muted">-</span>@endif</td>
      <td class="mono">{{ $s->app_version ?? '-' }}</td>
      <td class="n">@if($s && $s->clock_skew_seconds !== null)<span @class(['b', 'err' => abs($s->clock_skew_seconds) > C::SKEW])>{{ $s->clock_skew_seconds > 0 ? '+' : '' }}{{ $s->clock_skew_seconds }} dtk</span>@else - @endif</td>
      <td class="n">@if($s){{ $s->outbox_pending }} / {{ $s->outbox_failed }} / @if($s->outbox_parked > 0)<span class="b err">{{ $s->outbox_parked }}</span>@else 0 @endif
        @if($s->outbox_oldest_at)<div class="muted small">tertua {{ C::ago($s->outbox_oldest_at) }}</div>@endif @else - @endif</td>
      <td>{{ $s?->last_sent_at ? C::ago($s->last_sent_at) : '-' }}</td>
      <td class="n">{{ $s->applied_cursor ?? '-' }}</td>
      <td class="n">@if($r->lag > 0)<span class="b warn" title="{{ collect($r->lagTypes)->map(fn ($n, $t) => "$t: $n")->implode(', ') }}">{{ $r->lag }}</span>@else 0 @endif</td>
      <td>{{ $s?->open_shifts ? $s->open_shifts.' sejak '.$s->open_business_date : '-' }}</td>
    </tr>
  @endforeach
  </tbody>
</table>
</div>
<p class="muted small">Heartbeat dikirim setiap menit. Kuning bila lebih dari {{ C::LATE }} menit, ada publikasi yang belum diterapkan, atau ada event gagal kirim. Merah bila lebih dari {{ C::LOST }} menit, ada event parked, atau jam server outlet bergeser lebih dari {{ C::SKEW }} detik.</p>

<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <div class="bar"><h2 style="margin:0">Instance SAP B1</h2>
      <form class="grow" method="post" action="{{ route('core.central.sap-check') }}">@csrf<button class="btn alt sm">Cek koneksi sekarang</button></form></div>
    <table class="t">
      <thead><tr><th>Perusahaan</th><th>Instance</th><th>Service Layer</th><th>Cek terakhir</th></tr></thead>
      <tbody>
      @foreach ($companies as $c)
        <tr>
          <td>{{ $c->company->name }}</td>
          <td class="mono">{{ $c->company->sap_company_db }}</td>
          <td>@if($c->configured)<span class="b ok">terkonfigurasi</span>@else<span class="b mute">simulasi</span>@endif</td>
          <td>@if($c->last)<span class="b {{ $c->last['ok'] ? 'ok' : 'err' }}">{{ $c->last['ok'] ? 'OK' : 'Gagal' }}</span> {{ $c->last['ms'] }} ms · {{ C::ago($c->last['at']) }}
              @if(! $c->last['ok'])<div class="small">{{ $c->last['error'] ?? '' }}</div>@endif
            @else<span class="muted">belum pernah</span>@endif</td>
        </tr>
      @endforeach
      </tbody>
    </table>
    <p class="muted small">Kredensial tiap instance diisi di server Central (SAP_SOLO_*, SAP_LOMBOK_*). Instance tanpa URL memakai SAP simulasi.</p>
  </div>
  <div class="card">
    <h2>Layanan & job terjadwal</h2>
    <dl class="dl">
      <dt>API sinkronisasi</dt><dd><span class="mono">/api/v1/sync</span> events · changes · snapshot · ack · heartbeat</dd>
      <dt>Ditolak 24 jam</dt><dd>@if($inbox['rejected'])<span class="b err">{{ $inbox['rejected'] }}</span>@else 0 @endif event</dd>
      @foreach ($jobs as $j)
        <dt>{{ $j->label }}</dt><dd>@if($j->last){{ C::ago($j->last['at']) }} <span class="muted small">{{ collect($j->last['result'] ?? [])->map(fn ($v, $k) => "$k $v")->implode(', ') }}</span>@else<span class="muted">belum berjalan</span>@endif</dd>
      @endforeach
      <dt>Tutup hari 7 hari terakhir</dt><dd>
        <span class="b ok">{{ $reconciliation['matched'] ?? 0 }} cocok</span>
        <span class="b warn">{{ $reconciliation['waiting'] ?? 0 }} menunggu</span>
        <span class="b err">{{ $reconciliation['mismatch'] ?? 0 }} selisih</span>
        <a href="{{ route('core.reconciliation') }}">lihat</a></dd>
    </dl>
  </div>
</div>
@endsection
