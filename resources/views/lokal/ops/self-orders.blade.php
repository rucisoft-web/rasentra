@extends('lokal.layout')
@use('App\Support\Fmt')
@section('content')
<p class="muted">Pesanan dari QR di meja. Setelah diterima, item masuk ke pesanan meja seperti input dari Mobile Terminal dan dikirim ke dapur sesuai pengaturan pusat.</p>
@forelse ($pending as $so)
  <div class="card" style="margin-bottom:12px">
    <div style="display:flex;gap:12px;align-items:baseline;flex-wrap:wrap">
      <b>Meja {{ $tables[$so->dining_table_id] ?? '?' }}</b>
      <span class="muted">{{ $so->guest_name ?: 'Tamu' }} · {{ $so->guests }} orang · {{ Fmt::dt($so->created_at) }}</span>
      <span style="margin-left:auto" class="mono">± {{ Fmt::money($so->estimated_total) }}</span>
    </div>
    <ul>@foreach ($so->items as $i)<li>{{ $i['quantity'] }}× {{ $i['name'] }}@if(! empty($i['note'])) <span class="muted">({{ $i['note'] }})</span>@endif</li>@endforeach</ul>
    @if ($so->note)<p class="small">Catatan: {{ $so->note }}</p>@endif
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <form method="post" action="{{ route('lokal.self-orders.accept', $so) }}">@csrf<button class="btn">Terima</button></form>
      <form method="post" action="{{ route('lokal.self-orders.reject', $so) }}" style="display:flex;gap:6px">@csrf
        <input name="reason" required placeholder="Alasan tolak, mis. menu habis" style="min-width:220px"><button class="btn alt">Tolak</button></form>
    </div>
  </div>
@empty
  <div class="card muted">Tidak ada pesanan mandiri yang menunggu.</div>
@endforelse

<h3 style="margin-top:22px">Riwayat terakhir</h3>
<div class="tablewrap"><table class="t">
  <thead><tr><th>Waktu</th><th>Meja</th><th>Tamu</th><th class="n">Item</th><th>Status</th></tr></thead>
  <tbody>@foreach ($recent as $so)
    <tr><td>{{ Fmt::dt($so->handled_at) }}</td><td>{{ $tables[$so->dining_table_id] ?? '?' }}</td><td>{{ $so->guest_name ?: '-' }}</td><td class="n">{{ count($so->items) }}</td>
      <td>@if ($so->status === 'accepted')<span class="b ok">Diterima</span>@else<span class="b err">Ditolak</span> <span class="small muted">{{ $so->reject_reason }}</span>@endif</td></tr>
  @endforeach</tbody>
</table></div>
<script>setTimeout(() => location.reload(), 20000);</script>
@endsection
