@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
@include('core.ops._range')
<div class="grid g4" style="margin-bottom:14px">
  @foreach ($providers as $p)<div class="card kpi"><div class="label">{{ $p->provider }}</div><div class="value">{{ $p->n }}</div><div class="note">nilai {{ Fmt::money($p->amount) }} · ongkir {{ Fmt::money($p->fee) }}</div></div>@endforeach
</div>
<div class="tablewrap"><table class="t">
  <thead><tr><th>Tanggal</th><th>Outlet</th><th>Struk</th><th>Pemesan</th><th>Mitra / kurir</th><th class="n">Nilai</th><th class="n">Ongkir</th><th>Status</th><th>Janji / terkirim</th></tr></thead>
  <tbody>@forelse ($rows as $d)
    <tr><td>{{ $d->business_date->format('d/m') }}</td><td>{{ $outlets[$d->outlet_id] ?? '-' }}</td><td class="mono">{{ $d->doc_no }}</td><td>{{ $d->customer_name }}</td>
      <td>{{ $d->provider }}{{ $d->courier_name ? ' · '.$d->courier_name : '' }}</td><td class="n">{{ Fmt::money($d->order_amount) }}</td><td class="n">{{ Fmt::money($d->fee) }}</td>
      <td><span class="b {{ $status[$d->status][1] ?? 'mute' }}">{{ $status[$d->status][0] ?? $d->status }}</span></td>
      <td class="small">{{ $d->promised_at ? Fmt::dt($d->promised_at) : '-' }} / {{ $d->delivered_at ? Fmt::dt($d->delivered_at) : '-' }}</td></tr>
  @empty <tr><td colspan="9" class="muted">Belum ada data delivery.</td></tr>
  @endforelse</tbody>
</table></div>
{{ $rows->links('core.pager') }}
@endsection
