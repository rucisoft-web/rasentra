@extends('lokal.layout')
@use('App\Support\Fmt')
@section('content')
<p class="muted">Pesanan kanal delivery yang sudah dibayar dalam 2 hari terakhir. Tugaskan kurir internal atau mitra, lalu perbarui status sampai terkirim.</p>
<div class="tablewrap"><table class="t">
  <thead><tr><th>Struk</th><th>Pemesan & alamat</th><th>Janji kirim</th><th class="n">Nilai</th><th>Pengiriman</th><th>Tindakan</th></tr></thead>
  <tbody>
  @forelse ($orders as $o)
    @php $d = $deliveries[$o->id] ?? null; $st = $status[$d->status ?? 'pending']; @endphp
    <tr>
      <td class="mono">{{ $o->doc_no }}</td>
      <td><b>{{ $o->customer_name }}</b> · {{ $o->customer_phone }}<br><span class="small muted">{{ $o->delivery_address }}</span></td>
      <td class="small">{{ $o->promised_at ? Fmt::dt($o->promised_at) : '-' }}</td>
      <td class="n">{{ Fmt::money($o->net_amount) }}</td>
      <td><span class="b {{ $st[1] }}">{{ $st[0] }}</span>
        @if ($d && $d->status !== 'pending')<br><span class="small">{{ $d->provider }}{{ $d->courier_name ? ' · '.$d->courier_name : '' }}{{ $d->external_ref ? ' · '.$d->external_ref : '' }} · ongkir {{ Fmt::money($d->fee) }}</span>@endif</td>
      <td>
        @if (! $d || $d->status === 'pending')
          <form method="post" action="{{ route('lokal.deliveries.assign', $o) }}" style="display:flex;gap:6px;flex-wrap:wrap">@csrf
            <select name="provider">@foreach ($providers as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach</select>
            <select name="courier_id"><option value="">Kurir internal…</option>@foreach ($couriers as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
            <input name="external_ref" placeholder="No. order mitra" style="width:130px">
            <input name="fee" type="number" min="0" step="500" placeholder="Ongkir" style="width:90px" value="{{ $d ? (int) $d->fee : '' }}">
            <button class="btn sm">Tugaskan</button>
          </form>
        @elseif (in_array($d->status, ['assigned', 'on_the_way'], true))
          <form method="post" action="{{ route('lokal.deliveries.status', $d) }}" style="display:flex;gap:6px">@csrf
            @if ($d->status === 'assigned')<button class="btn sm" name="status" value="on_the_way">Berangkat</button>@endif
            @if ($d->status === 'on_the_way')<button class="btn sm" name="status" value="delivered">Terkirim</button>@endif
            <input name="note" placeholder="Alasan bila gagal" style="width:150px">
            <button class="btn alt sm" name="status" value="failed">Gagal</button>
          </form>
        @elseif ($d->status === 'failed')
          <form method="post" action="{{ route('lokal.deliveries.status', $d) }}">@csrf<button class="btn alt sm" name="status" value="pending">Kirim ulang</button></form>
        @else
          <span class="small muted">{{ Fmt::dt($d->delivered_at) }}</span>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Belum ada pesanan delivery.</td></tr>
  @endforelse
  </tbody>
</table></div>
@endsection
