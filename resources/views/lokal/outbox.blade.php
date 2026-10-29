@extends('lokal.layout')
@use('App\Support\Fmt')
@section('content')
<div class="bar">
  <div class="actions">
    <a href="{{ route('lokal.outbox') }}" class="b {{ $status === 'open' ? 'info' : 'mute' }}">Belum terkirim {{ collect(['pending', 'sending', 'failed', 'parked', 'held'])->sum(fn ($s) => $counts[$s] ?? 0) }}</a>
    @foreach ($statuses as $k => [$label, $tone])
      <a href="{{ route('lokal.outbox', ['status' => $k]) }}" class="b {{ $tone }}" @if($status === $k) style="outline:2px solid currentColor" @endif>{{ $label }} {{ $counts[$k] ?? 0 }}</a>
    @endforeach
    <a href="{{ route('lokal.outbox', ['status' => 'all']) }}" class="b mute">Semua</a>
  </div>
  <div class="grow" style="display:flex;gap:8px">
    @if (($counts['failed'] ?? 0) + ($counts['parked'] ?? 0) > 0)
      <form method="post" action="{{ route('lokal.outbox.retry-all') }}">@csrf<button class="btn alt">Antrekan ulang semua yang gagal</button></form>
    @endif
    <form method="post" action="{{ route('lokal.push') }}">@csrf<button class="btn">Kirim sekarang</button></form>
  </div>
</div>
<p class="muted small">Setiap transaksi yang selesai di outlet masuk antrean ini bersamaan dengan transaksinya, lalu dikirim ke Rasentra Central. Kirim ulang aman: Central menolak duplikat. Event yang gagal dicoba lagi dengan jeda makin panjang; setelah 20 kali berhenti dicoba dan perlu diantrekan ulang setelah penyebabnya diperbaiki.</p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Waktu</th><th>Jenis</th><th>Dokumen</th><th>Tanggal bisnis</th><th>Status</th><th class="n">Percobaan</th><th>Coba lagi</th><th>Error terakhir</th><th></th></tr></thead>
  <tbody>
  @forelse ($events as $e)
    @php [$label, $tone] = $statuses[$e->status] ?? [$e->status, 'mute']; @endphp
    <tr>
      <td>{{ Fmt::dt($e->occurred_at) }}</td>
      <td class="mono small">{{ $e->event_type }}</td>
      <td class="mono small">{{ $e->payload['doc_no'] ?? ($e->payload['business_date'] ?? \Illuminate\Support\Str::limit($e->entity_id, 13)) }}@if($e->entity_version > 1) <span class="muted">v{{ $e->entity_version }}</span>@endif</td>
      <td>{{ $e->business_date->toDateString() }}</td>
      <td><span class="b {{ $tone }}">{{ $label }}</span></td>
      <td class="n">{{ $e->attempts }}</td>
      <td>{{ $e->status === 'failed' && $e->next_attempt_at ? Fmt::dt($e->next_attempt_at) : '-' }}</td>
      <td class="small">{{ \Illuminate\Support\Str::limit($e->last_error, 120) }}</td>
      <td>@if(in_array($e->status, ['failed', 'parked', 'held'], true))<form class="inline" method="post" action="{{ route('lokal.outbox.retry', $e) }}">@csrf<button class="btn alt sm">Antrekan ulang</button></form>@endif</td>
    </tr>
  @empty
    <tr><td colspan="9" class="muted">Tidak ada event. Semua transaksi sudah diterima Central.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $events->links('core.pager') }}
@endsection
