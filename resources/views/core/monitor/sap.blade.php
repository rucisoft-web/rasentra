@extends('core.layout')
@use('App\Support\Fmt')
@section('content')
<div class="tabs">
  <a href="{{ route('core.monitor.sap', ['status' => request('status')]) }}" @class(['on' => ! $company])>Semua instance</a>
  @foreach ($companies as $c)<a href="{{ route('core.monitor.sap', ['company' => $c->sap_company_db, 'status' => request('status')]) }}" @class(['on' => $company === $c->sap_company_db])>{{ $c->name }} · <span class="mono">{{ $c->sap_company_db }}</span></a>@endforeach
</div>
<div class="bar">
  <div class="actions">
    @foreach ($statuses as $k => [$label, $tone])
      <a href="{{ route('core.monitor.sap', ['status' => $k, 'company' => $company]) }}" class="b {{ $tone }}" @if(request('status') === $k) style="outline:2px solid currentColor" @endif>{{ $label }} {{ $counts[$k] ?? 0 }}</a>
    @endforeach
    <a href="{{ route('core.monitor.sap', ['company' => $company]) }}" class="b mute">Semua</a>
  </div>
  <form class="grow" method="post" action="{{ route('core.monitor.sap.post') }}">@csrf<button class="btn">Posting {{ $waiting }} penjualan yang menunggu</button></form>
</div>
<p class="muted small">Setiap penjualan diposting ke instance SAP milik CV pemilik outlet sebagai A/R Invoice lalu Incoming Payment. Sebelum membuat dokumen, sistem mencari U_POS_SRC di SAP sehingga posting ulang tidak membuat dokumen ganda. Status "Perlu dicek" muncul saat SAP tidak menjawab dan hasilnya belum pasti.</p>
<div class="tablewrap">
<table class="t">
  <thead><tr><th>Dokumen</th><th>Outlet</th><th>Instance</th><th>Objek SAP</th><th class="n">Nilai</th><th>Status</th><th class="n">Percobaan</th><th>DocNum</th><th>Catatan</th><th></th></tr></thead>
  <tbody>
  @forelse ($docs as $d)
    @php [$label, $tone] = $statuses[$d->status] ?? [$d->status, 'mute']; $o = $orders[$d->source_id] ?? null; @endphp
    <tr>
      <td class="mono">{{ $o->doc_no ?? \Illuminate\Support\Str::limit($d->source_id, 13) }}</td>
      <td>{{ $o->code ?? '-' }}</td>
      <td class="mono small">{{ $d->company_db }}</td>
      <td>{{ $d->sap_object }}</td>
      <td class="n">{{ $o && $o->net_amount !== null ? Fmt::money($o->net_amount) : '-' }}</td>
      <td><span class="b {{ $tone }}">{{ $label }}</span></td>
      <td class="n">{{ $d->attempts }}</td>
      <td class="mono">{{ $d->doc_num ?? '-' }}</td>
      <td class="small">{{ \Illuminate\Support\Str::limit($d->last_error, 90) }}</td>
      <td class="actions">
        <a class="btn alt sm" href="{{ route('core.monitor.sap.show', $d) }}">Detail</a>
        @if (in_array($d->status, ['needs_review', 'failed', 'partially_posted'], true))
          <form class="inline" method="post" action="{{ route('core.monitor.sap.retry', $d) }}">@csrf<button class="btn sm">Cek & posting ulang</button></form>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="10" class="muted">Belum ada dokumen SAP.</td></tr>
  @endforelse
  </tbody>
</table>
</div>
{{ $docs->links('core.pager') }}
@endsection
