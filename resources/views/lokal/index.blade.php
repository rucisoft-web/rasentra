@extends('lokal.layout')
@use('App\Support\Fmt')
@use('App\Domain\Lokal\LokalStatus')
@use('App\Http\Controllers\Core\CentralController', 'C')
@section('content')
@php
    $centralBadge = ['online' => ['Terhubung', 'ok'], 'offline' => ['Terputus', 'err'], 'unknown' => ['Belum dicek', 'mute']][$central['state']];
    $queued = $outbox['pending'] + $outbox['failed'] + $outbox['parked'];
    $lag = $sync['central_cursor'] !== null ? max(0, $sync['central_cursor'] - $sync['cursor']) : null;
    $pull = $sync['last_pull'];
@endphp
@if ($central['state'] === 'offline')
  <div class="alert err">Central sedang tidak terhubung{{ $central['last_ok'] ? ' sejak '.C::ago($central['last_ok']) : '' }}. Kasir, dapur dan gudang tetap berjalan normal; {{ $queued }} transaksi menunggu di antrean dan terkirim otomatis saat koneksi kembali.</div>
@endif

<div class="grid g4">
  <div class="card kpi"><div class="label">Rasentra Central</div><div class="value"><span class="b {{ $centralBadge[1] }}">{{ $centralBadge[0] }}</span></div>
    <div class="note">heartbeat {{ C::ago($central['at']) }}@if($central['skew'] !== null) · selisih jam {{ $central['skew'] }} dtk @endif</div></div>
  <div class="card kpi"><div class="label">Antrean kirim</div><div class="value">{{ $queued }}</div>
    <div class="note">@if($outbox['parked'])<span class="b err">{{ $outbox['parked'] }} berhenti dicoba</span> @endif{{ $outbox['failed'] }} gagal · terkirim terakhir {{ C::ago($outbox['last_acked']) }}</div></div>
  <div class="card kpi"><div class="label">Penjualan hari ini</div><div class="value">{{ Fmt::money($today['net']) }}</div>
    <div class="note">{{ $today['orders'] }} struk · tanggal bisnis {{ $today['date'] }}</div></div>
  <div class="card kpi"><div class="label">Publikasi dari Central</div><div class="value">{{ $sync['cursor'] }}</div>
    <div class="note">@if($lag)<span class="b warn">{{ $lag }} di belakang</span> @endif tarik terakhir {{ C::ago($pull['at'] ?? null) }}</div></div>
</div>

<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <h2>Sinkronisasi</h2>
    <dl class="dl">
      <dt>Alamat Central</dt><dd class="mono small">{{ config('rasentra.central.url') ?: 'belum diatur' }}</dd>
      <dt>Identitas server</dt><dd class="mono">{{ config('rasentra.source_id') }}</dd>
      <dt>Event tertua di antrean</dt><dd>{{ $outbox['oldest'] ? Fmt::dt($outbox['oldest']).' ('.C::ago($outbox['oldest']).')' : '-' }}</dd>
      <dt>Tarik publikasi terakhir</dt><dd>@if($pull){{ $pull['applied'] }} diterapkan · cursor {{ $pull['cursor'] }}@if($pull['error'])<div class="small" style="color:var(--red)">{{ $pull['error'] }}</div>@endif @else - @endif</dd>
      @if ($central['error'])<dt>Error koneksi</dt><dd class="small" style="color:var(--red)">{{ \Illuminate\Support\Str::limit($central['error'], 200) }}</dd>@endif
    </dl>
    <div class="actions" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" action="{{ route('lokal.push') }}">@csrf<button class="btn">Kirim antrean sekarang</button></form>
      <form method="post" action="{{ route('lokal.pull') }}">@csrf<button class="btn alt">Tarik publikasi</button></form>
      <form method="post" action="{{ route('lokal.heartbeat') }}">@csrf<button class="btn alt">Cek koneksi Central</button></form>
      <a class="btn alt" href="{{ route('lokal.outbox') }}">Lihat antrean</a>
    </div>
    <p class="muted small">Otomatis: antrean dikirim tiap 5 detik, publikasi ditarik tiap 30 detik, heartbeat tiap menit.</p>
  </div>

  <div class="card">
    <h2>Shift yang sedang buka</h2>
    @if ($shifts->isEmpty())
      <p class="muted">Tidak ada shift yang buka.</p>
    @else
      <table class="t"><thead><tr><th>Petugas</th><th>Tanggal bisnis</th><th>Dibuka</th><th class="n">Modal awal</th></tr></thead><tbody>
        @foreach ($shifts as $s)
          <tr><td>{{ $s->user?->name }}</td><td>{{ $s->business_date->toDateString() }}</td><td>{{ Fmt::dt($s->opened_at) }}</td><td class="n">{{ Fmt::money($s->opening_cash) }}</td></tr>
        @endforeach
      </tbody></table>
    @endif
    <p class="muted small">{{ $staff }} petugas aktif di outlet ini. <a href="{{ route('lokal.close-day') }}">Tutup hari</a></p>
  </div>
</div>

<div class="grid g2" style="margin-top:14px">
  <div class="card">
    <h2>Penyimpanan & backup</h2>
    <dl class="dl">
      <dt>Ruang disk kosong</dt><dd>{{ LokalStatus::bytes($storage['free']) }} dari {{ LokalStatus::bytes($storage['total']) }}
        @if($storage['free'] !== null && $storage['total'] && $storage['free'] / $storage['total'] < 0.1)<span class="b err">hampir penuh</span>@endif</dd>
      <dt>Ukuran database</dt><dd>{{ LokalStatus::bytes($storage['database']) }}</dd>
      <dt>Backup terakhir</dt><dd>@if($storage['backup']){{ C::ago($storage['backup']['at']) }} <span class="mono small">{{ $storage['backup']['result']['file'] ?? '' }}</span>@else<span class="b warn">belum pernah</span>@endif</dd>
    </dl>
    @if ($backups)
      <table class="t" style="margin-top:8px"><tbody>@foreach ($backups as $b)<tr><td class="mono small">{{ $b['file'] }}</td><td class="n">{{ LokalStatus::bytes($b['bytes']) }}</td></tr>@endforeach</tbody></table>
    @endif
    <form method="post" action="{{ route('lokal.backup') }}" style="margin-top:12px">@csrf<button class="btn alt">Backup sekarang</button></form>
    <p class="muted small">Backup otomatis setiap hari pukul 02.00, {{ \App\Domain\Lokal\Backup::KEEP }} berkas terakhir disimpan di server ini.</p>
  </div>
  <div class="card">
    <h2>Server</h2>
    <dl class="dl">
      <dt>Outlet</dt><dd><b>{{ $outlet->code }}</b> {{ $outlet->name }} · {{ $outlet->timezone }}</dd>
      <dt>Usaha</dt><dd>{{ collect([$outlet->retail_name, $outlet->restaurant_name])->filter()->implode(' & ') ?: '-' }}</dd>
      <dt>Versi aplikasi</dt><dd class="mono">{{ config('rasentra.version') }}</dd>
      <dt>Profil</dt><dd class="mono">{{ config('rasentra.profile') }}</dd>
      <dt>Jam server</dt><dd>{{ now($outlet->timezone)->format('d M Y H:i:s') }} ({{ $outlet->timezone }})</dd>
      <dt>Health check</dt><dd><a class="mono small" href="{{ route('lokal.health') }}">{{ route('lokal.health') }}</a></dd>
    </dl>
  </div>
</div>
@endsection
